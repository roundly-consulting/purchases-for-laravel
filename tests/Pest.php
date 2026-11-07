<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\Crypto\Hash\Hmac;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Testing\TestKeys;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Tests\Fixtures\SwappedModelsTestCase;
use RoundlyConsulting\Purchases\Tests\Fixtures\TrimmedRoutesConfigTestCase;
use RoundlyConsulting\Purchases\Tests\Fixtures\UlidOwnersTestCase;
use RoundlyConsulting\Purchases\Tests\HostTestCase;
use RoundlyConsulting\Purchases\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/ArchTest.php', __DIR__.'/Feature', __DIR__.'/Unit');

/*
 * `tests/Host` is exercised as a real host: nothing published, nothing auto-loaded, so
 * the schema starts EMPTY. Migrations are publish-only, and the normal TestCase
 * pre-loads them purely so the rest of the suite has tables — which is exactly what a
 * host does not do, and what `purchases:install` exists to fix. Pest will not let a
 * nested path narrow a broader `in()`, so the two roots are listed side by side.
 */
uses(HostTestCase::class)->in(__DIR__.'/Host');

/*
 * The model-swap proofs need every `purchases.models.*` key pointed at a host subclass
 * BEFORE the providers boot — which is what a real host does, by writing it into
 * `config/purchases.php`. Pest binds a test case per DIRECTORY, not per file, so they get
 * their own base case and their own directory.
 */
uses(SwappedModelsTestCase::class)->in(__DIR__.'/ModelSwap');

/*
 * A host whose published config overrides only part of `routes`: the merge is shallow, so the
 * switch is missing before the providers boot. Its own base case, so its own directory.
 */
uses(TrimmedRoutesConfigTestCase::class)->in(__DIR__.'/RoutesSwitch');

/*
 * A host with ULID-keyed owners: `purchases.key_type` set before the migrations run, so
 * every owner column is a ULID column. Its own base case, so its own directory.
 */
uses(UlidOwnersTestCase::class)->in(__DIR__.'/UlidOwners');

/**
 * A committed Apple-shaped fixture (tests/Fixtures/apple).
 *
 * The chain fixtures are static on purpose: their fingerprints and validity
 * windows are frozen vectors, so they survive an engine swap and make the
 * expiry matrix deterministic.
 */
function appleFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/apple/'.$name);
}

/**
 * A committed certificate as one `x5c` header entry: standard (padded) base64
 * of the DER — RFC 7515 §4.1.6, NOT base64url.
 */
function appleFixtureX5c(string $name): string
{
    return (string) preg_replace('/-----.*?-----|\s+/', '', appleFixture($name));
}

/**
 * The genuine fixture chain, leaf → root.
 *
 * @return list<string>
 */
function appleFixtureChain(): array
{
    return [
        appleFixtureX5c('leaf.pem'),
        appleFixtureX5c('intermediate.pem'),
        appleFixtureX5c('root.pem'),
    ];
}

/**
 * Mint an Apple-shaped ES256 notification signed by a committed private key.
 *
 * @param  array<string, mixed>  $claims
 * @param  list<string>  $x5c
 */
function appleFixtureToken(array $claims, array $x5c, string $keyFixture = 'leaf-key.pem'): string
{
    $signer = new Es(EcKey::private(appleFixture($keyFixture)));

    return (new Jws)->sign(['x5c' => $x5c], $claims, $signer);
}

/**
 * The production verifier with its anchors swapped for a throwaway CA's, so the
 * positive trust path can be exercised (we cannot sign with Apple's key).
 * Everything else — CHAIN_LENGTH, the linkage check, the validity ruling, the
 * ES256 pin — is the production code.
 *
 * @param  list<string>  $fingerprints  [intermediate, root], SHA-1, lower-case hex
 */
function appleVerifierPinnedTo(array $fingerprints): JwsVerifier
{
    return new class($fingerprints) extends JwsVerifier
    {
        /** @param  list<string>  $pins */
        public function __construct(private readonly array $pins)
        {
            parent::__construct();
        }

        /** @return list<string> */
        protected function fingerprints(): array
        {
            return $this->pins;
        }
    };
}

/*
 * Helpers shared ACROSS test files live here and only here. Under `--parallel` each
 * worker loads just the files it runs (plus this one), so a helper declared inside
 * another test file is undefined whenever that file lands on a different worker.
 * The ArchTest "shared test helpers" guard enforces this.
 */

/**
 * A fresh RSA key pair.
 *
 * @return array{0: string, 1: string} private and public PEM
 */
function rsaKeyPair(int $bits = 2048): array
{
    $key = TestKeys::rsa($bits);

    return [$key->privatePem(), $key->publicPem()];
}

/**
 * A stored, verified audit notification for a result — what the webhook path writes.
 */
function auditedNotification(ProviderResult $result): PurchaseNotification
{
    return PurchaseNotification::query()->create([
        'provider' => $result->provider(),
        'type' => $result->type()->value,
        'signature_verified' => true,
        'payload' => NotificationResultFactory::snapshot($result),
        'processed_at' => null,
    ]);
}

/**
 * Link a purchase or subscription to its owner through the `owner` morph.
 *
 * @template TModel of Purchase|Subscription
 *
 * @param  TModel  $owned
 * @return TModel
 */
function ownedBy(Model $owner, Purchase|Subscription $owned): Purchase|Subscription
{
    $owned->owner()->associate($owner)->save();

    return $owned;
}

/**
 * A Stripe webhook request for an event, signed with the given webhook secret the way
 * Stripe signs it (`t=<now>,v1=<HMAC-SHA256 of "t.body">`).
 *
 * @param  array<string, mixed>  $event
 */
function stripeSignedRequest(array $event, string $secret = 'whsec_test'): Request
{
    $body = (string) json_encode($event);
    $timestamp = Carbon::now()->getTimestamp();
    $signature = (new Hmac(HashAlgorithm::Sha256))->signHex("{$timestamp}.{$body}", $secret);

    $request = Request::create('/purchases/webhooks/stripe', 'POST', content: $body);
    $request->headers->set('Stripe-Signature', "t={$timestamp},v1={$signature}");

    return $request;
}

/**
 * Answer Stripe's Invoice Payments lookup — which invoice a PaymentIntent pays (and which
 * PaymentIntent paid an invoice), if any — for the rest of the test. Call it again to change
 * the answer: the stub is registered once per test and reads the latest one.
 */
function stripeInvoicePayments(?string $invoice, ?string $paymentIntent = null): void
{
    if (! app()->bound('tests.stripe.invoice')) {
        Http::fake(['api.stripe.com/v1/invoice_payments*' => function (): mixed {
            ['invoice' => $invoice, 'payment_intent' => $paymentIntent] = app('tests.stripe.invoice');
            $payment = ['type' => 'payment_intent'] + (is_string($paymentIntent) ? ['payment_intent' => $paymentIntent] : []);

            return Http::response([
                'object' => 'list',
                'data' => is_string($invoice) ? [['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => $invoice, 'is_default' => true, 'status' => 'paid', 'payment' => $payment]] : [],
                'has_more' => false,
            ]);
        }]);
    }

    // Wrapped: the container treats a bare null instance as unbound.
    app()->instance('tests.stripe.invoice', ['invoice' => $invoice, 'payment_intent' => $paymentIntent]);
}

/**
 * A Stripe Subscription object in the current API shape (periods on the items): a per-seat
 * item (`si_seats`, quantity 10) and a metered storage item (`si_storage`). Top-level keys
 * are overridden as given.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeBillingSubscription(array $overrides = []): array
{
    return $overrides + [
        'id' => 'sub_1',
        'object' => 'subscription',
        'customer' => 'cus_1',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'cancel_at' => null,
        'canceled_at' => null,
        'trial_end' => null,
        'metadata' => [],
        'items' => [
            'object' => 'list',
            'data' => [
                stripeBillingItem('si_seats', 'price_seats', 10),
                stripeBillingItem('si_storage', 'price_storage', null),
            ],
            'has_more' => false,
        ],
    ];
}

/**
 * One subscription item: a licensed price with a quantity, or (quantity null) a metered
 * price, which Stripe sends without one.
 *
 * @return array<string, mixed>
 */
function stripeBillingItem(string $id, string $price, ?int $quantity, int $unitAmount = 1200, string $start = '2026-11-01', string $end = '2026-12-01'): array
{
    $item = [
        'id' => $id,
        'object' => 'subscription_item',
        'current_period_start' => Carbon::parse($start, 'UTC')->getTimestamp(),
        'current_period_end' => Carbon::parse($end, 'UTC')->getTimestamp(),
        'price' => [
            'id' => $price,
            'object' => 'price',
            'nickname' => null,
            'lookup_key' => null,
            'product' => 'prod_'.$price,
            'currency' => 'eur',
            'unit_amount' => $unitAmount,
            'recurring' => ['interval' => 'month', 'usage_type' => $quantity === null ? 'metered' : 'licensed'],
        ],
    ];

    return $quantity === null ? $item : $item + ['quantity' => $quantity];
}

/**
 * A Stripe webhook event for an object, created at the given time, through the real
 * signature check and recording pipeline (`Purchases::handle()`).
 *
 * @param  array<string, mixed>  $object
 */
function stripeWebhook(string $type, array $object, int|Carbon $created): Model
{
    $created = $created instanceof Carbon ? $created->getTimestamp() : $created;

    return Purchases::handle('stripe', stripeSignedRequest([
        'id' => 'evt_'.$type.'_'.$created,
        'type' => $type,
        'created' => $created,
        'data' => ['object' => $object],
    ]));
}

/**
 * A Stripe request's form body exactly as sent on the wire — every value a string, a
 * boolean the word `true` / `false` — rather than the PHP data the fake keeps.
 *
 * @return array<array-key, mixed>
 */
function stripeForm(HttpRequest $request): array
{
    parse_str($request->body(), $form);

    return $form;
}
