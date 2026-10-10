<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\ChargebackReceived;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Events\SubscriptionExpired;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\EventType;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentIntentStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Providers\Stripe\StripeClient;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Invoice;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentIntent;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\StripeEvent;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

/**
 * A callback id as the client may leave it out: no key, null, or an empty string.
 *
 * @return array<string, mixed>
 */
function absentStripeId(string $key, string $how): array
{
    return match ($how) {
        'missing' => [],
        'null' => [$key => null],
        default => [$key => ''],
    };
}

function configureStripe(): void
{
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_123',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
}

function signedWebhook(string $payload): Request
{
    $timestamp = Carbon::now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

    $request = Request::create('/webhook', 'POST', content: $payload);
    $request->headers->set('Stripe-Signature', "t={$timestamp},v1={$signature}");

    return $request;
}

beforeEach(function (): void {
    configureStripe();
    // A PaymentIntent on a current API version names no invoice, so Stripe is asked.
    stripeInvoicePayments(null);
});

afterEach(fn () => Carbon::setTestNow());

it('decodes a payment_intent.succeeded webhook into an event', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_1', 'status' => 'succeeded', 'amount' => 1999, 'currency' => 'usd']],
    ]);

    $event = (new Stripe)->notification(signedWebhook($payload));

    expect($event->type)->toBe(EventType::PaymentIntentSucceeded)
        ->and($event->id)->toBe('evt_1')
        ->and($event->object['id'])->toBe('pi_1');
});

it('carries the event creation time on every result', function (): void {
    $result = stripeResultFor('payment_intent.succeeded', ['id' => 'pi_when', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd', 'invoice' => null], created: 1_700_000_777);

    expect($result->occurredAt()?->getTimestamp())->toBe(1_700_000_777)
        ->and(StripeEvent::fromRaw(['type' => 'invoice.paid'])->created)->toBeNull();
});

it('rejects a webhook with an invalid signature', function (): void {
    $request = Request::create('/webhook', 'POST', content: '{"id":"evt_1"}');
    $request->headers->set('Stripe-Signature', 't=1,v1=bad');

    (new Stripe)->notification($request);
})->throws(VerificationException::class);

it('throws when the webhook secret is not configured', function (): void {
    config()->set('purchases.settings.stripe.webhook_secret', null);

    (new Stripe)->notification(signedWebhook('{"id":"evt_1","type":"invoice.paid","data":{"object":{}}}'));
})->throws(VerificationException::class);

it('throws on a malformed webhook body', function (): void {
    (new Stripe)->notification(signedWebhook('not json'));
})->throws(VerificationException::class);

it('maps a payment intent event to a unified purchase result', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_2',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_2', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->providerId())->toBe('pi_2')
        ->and($result->price()?->minor())->toBe('5000')
        ->and($result->price()?->currency()->code)->toBe('EUR');
});

it('maps a failed payment intent event to a failed result', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_3',
        'type' => 'payment_intent.payment_failed',
        'data' => ['object' => ['id' => 'pi_3', 'status' => 'requires_payment_method', 'amount' => 5000, 'currency' => 'usd']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->status())->toBe(Status::Failed);
});

it('maps a subscription event to a unified subscription result', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_4',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_1',
            'status' => 'active',
            'customer' => 'cus_1',
            'current_period_start' => 1700000000,
            'current_period_end' => 1702592000,
            'trial_end' => null,
        ]],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->providerId())->toBe('sub_1')
        ->and($result->activeFrom())->not->toBeNull()
        ->and($result->endsAt())->not->toBeNull();
});

it('retrieves a payment intent', function (): void {
    Http::fake([
        '*/payment_intents/pi_9' => Http::response(['id' => 'pi_9', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']),
    ]);

    $intent = (new Stripe)->paymentIntent('pi_9');

    expect($intent->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($intent->amount?->minor())->toBe('1000');
});

it('retrieves a subscription and a session and an invoice', function (): void {
    Http::fake([
        '*/subscriptions/sub_9' => Http::response(['id' => 'sub_9', 'status' => 'active']),
        '*/checkout/sessions/cs_9' => Http::response(['id' => 'cs_9', 'payment_status' => 'paid', 'amount_total' => 2000, 'currency' => 'usd']),
        '*/invoices/in_9' => Http::response(['id' => 'in_9', 'status' => 'paid', 'amount_paid' => 3000, 'currency' => 'usd']),
    ]);

    $stripe = new Stripe;

    expect($stripe->subscription('sub_9')->id)->toBe('sub_9')
        ->and($stripe->session('cs_9')->amountTotal?->minor())->toBe('2000')
        ->and($stripe->invoice('in_9')->amountPaid?->minor())->toBe('3000');
});

it('pins the configured api version', function (): void {
    Http::fake(['*/payment_intents/*' => Http::response(['id' => 'pi_1', 'status' => 'succeeded'])]);

    (new Stripe)->paymentIntent('pi_1');

    Http::assertSent(fn ($request) => $request->hasHeader('Stripe-Version', '2026-05-27.dahlia'));
});

/*
 * An id goes into the request path, and a callback id comes from a client: unescaped, `?`
 * would add a query string (`?expand[]=customer`), `#` would cut the path short, and `/`
 * would walk it (`../`) to another endpoint.
 */
it('escapes the id it puts into a stripe api path', function (string $method, string $resource): void {
    Http::fake(['*' => Http::response(['id' => 'x'])]);

    (new Stripe)->{$method}('cs_1?expand[]=customer#/../../balance');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === "https://api.stripe.com/v1/{$resource}/cs_1%3Fexpand%5B%5D%3Dcustomer%23%2F..%2F..%2Fbalance");
})->with([
    'a checkout session' => ['session', 'checkout/sessions'],
    'a payment intent' => ['paymentIntent', 'payment_intents'],
    'a subscription' => ['subscription', 'subscriptions'],
    'an invoice' => ['invoice', 'invoices'],
]);

it('escapes a callback id into the stripe api path', function (string $key, string $resource): void {
    Http::fake(['*' => Http::response(['id' => 'x'])]);

    (new Stripe)->callback(new Request([$key => 'cs_1?expand[]=customer']));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === "https://api.stripe.com/v1/{$resource}/cs_1%3Fexpand%5B%5D%3Dcustomer");
})->with([
    'a session id' => ['session_id', 'checkout/sessions'],
    'a payment intent id' => ['payment_intent', 'payment_intents'],
]);

it('sends a valid stripe id unchanged', function (string $method, string $resource, string $id): void {
    Http::fake(['*' => Http::response(['id' => $id])]);

    (new Stripe)->{$method}($id);

    Http::assertSent(fn ($request): bool => $request->url() === "https://api.stripe.com/v1/{$resource}/{$id}");
})->with([
    'a checkout session' => ['session', 'checkout/sessions', 'cs_test_a1B2'],
    'a payment intent' => ['paymentIntent', 'payment_intents', 'pi_3MtwBwLkdIwHu7ix28a3tqPa'],
    'a subscription' => ['subscription', 'subscriptions', 'sub_1MowQVLkdIwHu7ixeRlqHVzs'],
    'an invoice' => ['invoice', 'invoices', 'in_1MtHbELkdIwHu7ixl4OzzPMv'],
]);

/*
 * No escaping keeps `.` or `..` a segment of its own: the HTTP client resolves it, so
 * `session('.')` would read the checkout session LIST and `..` the parent path. An empty
 * id lands on the list too.
 */
it('refuses an id that cannot stay one path segment, before asking stripe', function (string $method, string $id): void {
    Http::fake();

    expect(fn () => (new Stripe)->{$method}($id))
        ->toThrow(VerificationException::class, 'Malformed Stripe id.');

    Http::assertNothingSent();
})->with(['session', 'paymentIntent', 'subscription', 'invoice'])->with([
    'empty' => [''],
    'a dot' => ['.'],
    'two dots' => ['..'],
]);

it('refuses a callback id that cannot stay one path segment, before asking stripe', function (string $key, string $id): void {
    Http::fake();

    expect(fn () => (new Stripe)->callback(new Request([$key => $id])))
        ->toThrow(VerificationException::class, 'Malformed Stripe id.');

    Http::assertNothingSent();
})->with(['session_id', 'payment_intent'])->with([
    'a dot' => ['.'],
    'two dots' => ['..'],
]);

it('verifies a callback by session id', function (): void {
    Http::fake(['*/checkout/sessions/cs_1' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid'])]);

    $session = (new Stripe)->callback(new Request(['session_id' => 'cs_1']));

    expect($session->id)->toBe('cs_1');
});

it('verifies a callback by payment intent id', function (): void {
    Http::fake(['*/payment_intents/pi_1' => Http::response(['id' => 'pi_1', 'status' => 'succeeded'])]);

    $intent = (new Stripe)->callback(new Request(['payment_intent' => 'pi_1']));

    expect($intent->id)->toBe('pi_1');
});

it('throws when a callback has no identifiers', function (): void {
    (new Stripe)->callback(new Request);
})->throws(VerificationException::class);

it('refuses a session id that is not a string, before asking stripe', function (mixed $sessionId, array $also): void {
    Http::fake();

    // A session id that is there but not a string is the client's mistake, not "no session":
    // skipping it would verify the payment intent sent next to it instead.
    expect(fn () => (new Stripe)->callback(new Request(['session_id' => $sessionId] + $also)))
        ->toThrow(VerificationException::class, 'Malformed Stripe session id.');

    Http::assertNothingSent();
})->with([
    'an array' => [['cs_1']],
    'a keyed array' => [['id' => 'cs_1']],
    'an integer' => [1],
    'a boolean' => [true],
    'false' => [false],
])->with([
    'alone' => [[]],
    'next to a payment intent' => [['payment_intent' => 'pi_1']],
]);

it('refuses a payment intent id that is not a string, before asking stripe', function (mixed $paymentIntent, array $also): void {
    Http::fake();

    expect(fn () => (new Stripe)->callback(new Request(['payment_intent' => $paymentIntent] + $also)))
        ->toThrow(VerificationException::class, 'Malformed Stripe payment intent id.');

    Http::assertNothingSent();
})->with([
    'an array' => [['pi_1']],
    'a keyed array' => [['id' => 'pi_1']],
    'an integer' => [1],
    'a boolean' => [true],
    'false' => [false],
])->with([
    'alone' => [[]],
    'next to a session' => [['session_id' => 'cs_1']],
]);

it('verifies the session when both ids are given', function (): void {
    Http::fake([
        '*/checkout/sessions/cs_1' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid']),
        '*/payment_intents/*' => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
    ]);

    $verified = (new Stripe)->callback(new Request(['session_id' => 'cs_1', 'payment_intent' => 'pi_1']));

    expect($verified)->toBeInstanceOf(CheckoutSession::class)
        ->and($verified->id)->toBe('cs_1');

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/payment_intents/'));
});

it('verifies the payment intent when no session id is given', function (string $how): void {
    Http::fake([
        '*/payment_intents/pi_1' => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
        '*/checkout/sessions/*' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid']),
    ]);

    $verified = (new Stripe)->callback(new Request(absentStripeId('session_id', $how) + ['payment_intent' => 'pi_1']));

    expect($verified)->toBeInstanceOf(PaymentIntent::class)
        ->and($verified->id)->toBe('pi_1');

    Http::assertSentCount(1);
})->with(['missing', 'null', 'empty']);

it('verifies the session when no payment intent id is given', function (string $how): void {
    Http::fake(['*/checkout/sessions/cs_1' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid'])]);

    $verified = (new Stripe)->callback(new Request(['session_id' => 'cs_1'] + absentStripeId('payment_intent', $how)));

    expect($verified)->toBeInstanceOf(CheckoutSession::class)
        ->and($verified->id)->toBe('cs_1');
})->with(['missing', 'null', 'empty']);

it('says no id was provided when both are missing, null or empty', function (string $session, string $paymentIntent): void {
    Http::fake();

    expect(fn () => (new Stripe)->callback(new Request(absentStripeId('session_id', $session) + absentStripeId('payment_intent', $paymentIntent))))
        ->toThrow(VerificationException::class, 'No Stripe session or payment intent id provided.');

    Http::assertNothingSent();
})->with(['missing', 'null', 'empty'])->with(['missing', 'null', 'empty']);

/*
 * Stripe answering 4xx to a callback lookup (an unknown session, a malformed id) is the
 * client's mistake: it is a VerificationException, as every other bad callback input is,
 * so a host that catches only that answers it instead of failing with a 500.
 */
it('refuses a callback id stripe rejects, keeping stripe\'s answer', function (string $key, string $resource, string $label, int $status): void {
    Http::fake(["*/{$resource}/*" => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']], $status)]);

    expect(fn () => (new Stripe)->callback(new Request([$key => 'x_unknown'])))
        ->toThrow(function (VerificationException $e) use ($label, $status): void {
            expect($e->getMessage())->toBe("Stripe rejected the {$label}.")
                ->and($e->getPrevious())->toBeInstanceOf(RequestException::class)
                ->and($e->getPrevious()?->response->status())->toBe($status);
        });

    Http::assertSentCount(1);
})->with([
    'a session id' => ['session_id', 'checkout/sessions', 'session id'],
    'a payment intent id' => ['payment_intent', 'payment_intents', 'payment intent id'],
])->with([
    'not found' => [404],
    'bad request' => [400],
]);

/*
 * A Stripe outage, a rate limit and a refused secret key say nothing about the id: they stay
 * the HTTP client's exception, so the host retries them or answers 500 (and its error
 * reporting sees a broken key instead of a stream of "rejected" ids).
 */
it('leaves a stripe failure that is not about the id as it is', function (string $key, string $resource, int $status): void {
    Http::fake(["*/{$resource}/*" => Http::response(['error' => ['type' => 'api_error']], $status)]);

    expect(fn () => (new Stripe)->callback(new Request([$key => 'x_1'])))
        ->toThrow(function (RequestException $e) use ($status): void {
            expect($e->response->status())->toBe($status);
        });
})->with([
    'a session id' => ['session_id', 'checkout/sessions'],
    'a payment intent id' => ['payment_intent', 'payment_intents'],
])->with([
    'a server error' => [500],
    'unavailable' => [503],
    'rate limited' => [429],
    'an invalid secret key' => [401],
    'a restricted key without access' => [403],
]);

it('leaves a connection failure of a callback lookup as it is', function (): void {
    Http::fake(['*' => fn () => throw new ConnectException('Connection timed out.', new Psr7Request('GET', 'https://api.stripe.com/v1/checkout/sessions/cs_1'))]);

    expect(fn () => (new Stripe)->callback(new Request(['session_id' => 'cs_1'])))
        ->toThrow(ConnectionException::class);
});

it('leaves a stripe 4xx from a direct lookup as the http client\'s exception', function (): void {
    Http::fake(['*/checkout/sessions/*' => Http::response(['error' => ['code' => 'resource_missing']], 404)]);

    expect(fn () => (new Stripe)->session('cs_unknown'))->toThrow(RequestException::class);
});

it('leaves a stripe 4xx during a webhook lookup as the http client\'s exception', function (): void {
    // beforeEach already answers invoice_payments; the first matching stub wins, so start over.
    Http::swap(new HttpFactory);
    Http::fake(['api.stripe.com/v1/invoice_payments*' => Http::response(['error' => ['type' => 'invalid_request_error']], 400)]);

    expect(fn () => stripeResultFor('payment_intent.succeeded', ['id' => 'pi_lookup', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd']))
        ->toThrow(RequestException::class);
});

it('throws when the secret key is not configured', function (): void {
    config()->set('purchases.settings.stripe.secret', null);

    (new Stripe)->paymentIntent('pi_1');
})->throws(VerificationException::class);

it('maps a charge.refunded webhook to a refund result', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_refund',
        'type' => 'charge.refunded',
        'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_refund', 'amount_refunded' => 999, 'currency' => 'usd']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Refund)
        ->and($result->status())->toBe(Status::Refunded)
        ->and($result->providerId())->toBe('pi_refund')
        ->and($result->isChargeback())->toBeFalse()
        ->and($result->price()?->minor())->toBe('999');
});

it('maps a charge.dispute.created webhook to a chargeback', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_dispute',
        'type' => 'charge.dispute.created',
        'data' => ['object' => ['id' => 'dp_1', 'payment_intent' => 'pi_dispute', 'amount' => 1500, 'currency' => 'usd', 'reason' => 'fraudulent']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Refund)
        ->and($result->isChargeback())->toBeTrue()
        ->and($result->refundReason())->toBe('fraudulent')
        // Keyed on the dispute itself; the payment it disputes is the transaction.
        ->and($result->providerId())->toBe('dp_1')
        ->and($result->transactionId())->toBe('pi_dispute');
});

it('maps a past_due subscription update into a grace-period result', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_grace',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['id' => 'sub_grace', 'status' => 'past_due']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::InGracePeriod);
});

it('verifies stripe connectivity by fetching the balance', function (): void {
    Http::fake(['*/balance' => Http::response(['object' => 'balance'])]);

    $result = (new Stripe)->verifyConnectivity();

    expect($result->ok)->toBeTrue();
});

it('reports failed stripe connectivity gracefully', function (): void {
    Http::fake(['*/balance' => Http::response('nope', 401)]);

    $result = (new Stripe)->verifyConnectivity();

    expect($result->ok)->toBeFalse();
});

it('falls back to the charge id when a refund has no payment intent', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_norefintent',
        'type' => 'charge.refunded',
        'data' => ['object' => ['id' => 'ch_only', 'amount_refunded' => 500, 'currency' => 'usd']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->providerId())->toBe('ch_only')
        ->and($result->transactionId())->toBeNull();
});

it('falls back to the event id for a subscription without an object id', function (): void {
    $payload = (string) json_encode([
        'id' => 'evt_subnoid',
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['status' => 'active']],
    ]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->providerId())->toBe('evt_subnoid');
});

it('maps checkout session and invoice events with their own amount and status', function (string $type, array $object, string $minor, Status $status): void {
    $payload = (string) json_encode(['id' => 'evt_amt', 'type' => $type, 'data' => ['object' => $object + ['currency' => 'eur']]]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->price()?->minor())->toBe($minor)
        ->and($result->price()?->currency()->code)->toBe('EUR')
        ->and($result->status())->toBe($status);
})->with([
    'checkout.session.completed, paid' => ['checkout.session.completed', ['id' => 'cs_1', 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 2000], '2000', Status::Completed],
    'checkout.session.completed, no payment required' => ['checkout.session.completed', ['id' => 'cs_2', 'status' => 'complete', 'payment_status' => 'no_payment_required', 'amount_total' => 0], '0', Status::Completed],
    'checkout.session.completed, async payment pending' => ['checkout.session.completed', ['id' => 'cs_3', 'status' => 'complete', 'payment_status' => 'unpaid', 'amount_total' => 2500], '2500', Status::Pending],
    'invoice.paid' => ['invoice.paid', ['id' => 'in_1', 'status' => 'paid', 'amount_due' => 3000, 'amount_paid' => 3000], '3000', Status::Completed],
    'invoice.payment_failed' => ['invoice.payment_failed', ['id' => 'in_2', 'status' => 'open', 'amount_due' => 4000, 'amount_paid' => 0], '4000', Status::Failed],
]);

it('records nothing for a stripe event it does not map', function (): void {
    $payload = (string) json_encode(['id' => 'evt_other', 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1', 'object' => 'customer']]]);

    $result = (new Stripe)->result(signedWebhook($payload));

    expect($result->type())->toBe(ResultType::Unknown)
        ->and(app(RecordProviderResultAction::class)->execute($result))->toBeNull()
        ->and(Subscription::query()->count())->toBe(0)
        ->and(Purchase::query()->count())->toBe(0);
});

it('keeps a partially refunded purchase completed', function (bool $fullyRefunded, Status $expected): void {
    Event::fake([PurchaseRefunded::class]);
    $sync = app(RecordProviderResultAction::class);

    $sync->execute((new Stripe)->result(signedWebhook((string) json_encode([
        'id' => 'evt_paid', 'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_part', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']],
    ]))));

    $refund = $sync->execute((new Stripe)->result(signedWebhook((string) json_encode([
        'id' => 'evt_refund_part', 'type' => 'charge.refunded',
        'data' => ['object' => [
            'id' => 'ch_part', 'payment_intent' => 'pi_part', 'amount' => 5000,
            'amount_refunded' => $fullyRefunded ? 5000 : 1000, 'refunded' => $fullyRefunded, 'currency' => 'eur',
        ]],
    ]))));

    expect(Purchase::query()->sole()->status)->toBe($expected)
        ->and($refund)->toBeInstanceOf(PurchaseRefund::class);

    Event::assertDispatched(PurchaseRefunded::class);
})->with([
    'partial refund' => [false, Status::Completed],
    'full refund' => [true, Status::Refunded],
]);

/**
 * @param  array<string, mixed>  $object
 */
function stripeResultFor(string $type, array $object, ?int $created = null): ProviderResult
{
    // Stripe stamps every event with its creation time; successive calls here get
    // successive seconds, the order they were sent in.
    static $clock = 1_700_000_000;
    $created ??= ++$clock;

    return (new Stripe)->result(signedWebhook((string) json_encode(['id' => 'evt_'.md5($type.json_encode($object)), 'type' => $type, 'created' => $created, 'data' => ['object' => $object]])));
}

it('does not record a subscription or setup checkout as a one-off purchase', function (string $mode): void {
    $result = stripeResultFor('checkout.session.completed', [
        'id' => 'cs_'.$mode, 'mode' => $mode, 'status' => 'complete', 'payment_status' => 'paid',
        'amount_total' => 2000, 'currency' => 'eur', 'subscription' => 'sub_1',
    ]);

    expect($result->type())->toBe(ResultType::Notification)
        ->and(app(RecordProviderResultAction::class)->execute($result))->toBeNull()
        ->and(Purchase::query()->count())->toBe(0);
})->with(['subscription', 'setup']);

it('records a payment checkout and its payment intent as one purchase', function (): void {
    $sync = app(RecordProviderResultAction::class);

    $session = stripeResultFor('checkout.session.completed', [
        'id' => 'cs_pay', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
        'amount_total' => 2000, 'currency' => 'eur', 'payment_intent' => 'pi_pay',
    ]);
    $sync->execute($session);
    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_pay', 'status' => 'succeeded', 'amount' => 2000, 'currency' => 'eur']));

    expect($session->providerId())->toBe('pi_pay')
        ->and($session->transactionId())->toBe('pi_pay')
        ->and(Purchase::query()->sole()->provider_id)->toBe('pi_pay');
});

it('leaves subscription invoices and their payments to the subscription', function (string $type, array $object): void {
    $result = stripeResultFor($type, $object + ['currency' => 'eur']);

    expect($result->type())->toBe(ResultType::Notification)
        ->and(app(RecordProviderResultAction::class)->execute($result))->toBeNull()
        ->and(Purchase::query()->count())->toBe(0);
})->with([
    'renewal invoice paid (parent)' => ['invoice.paid', ['id' => 'in_1', 'status' => 'paid', 'amount_paid' => 999, 'billing_reason' => 'subscription_cycle', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]]],
    'renewal invoice failed (parent)' => ['invoice.payment_failed', ['id' => 'in_2', 'status' => 'open', 'amount_due' => 999, 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']]]],
    'first invoice (legacy subscription field)' => ['invoice.paid', ['id' => 'in_3', 'status' => 'paid', 'amount_paid' => 999, 'subscription' => 'sub_1']],
    'subscription billing reason only' => ['invoice.paid', ['id' => 'in_4', 'status' => 'paid', 'amount_paid' => 999, 'billing_reason' => 'subscription_create']],
    'invoice payment intent (legacy invoice field)' => ['payment_intent.succeeded', ['id' => 'pi_inv', 'status' => 'succeeded', 'amount' => 999, 'invoice' => 'in_3']],
]);

it('still records a one-off invoice as a purchase', function (): void {
    $result = stripeResultFor('invoice.paid', ['id' => 'in_once', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur']);

    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->price()?->minor())->toBe('4500');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeDispute(string $status, array $overrides = []): array
{
    return $overrides + ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_d', 'payment_intent' => 'pi_d', 'amount' => 5000, 'currency' => 'eur', 'reason' => 'fraudulent', 'status' => $status];
}

it('maps a dispute through its lifecycle without mistaking a won one for a chargeback', function (): void {
    Event::fake([ChargebackReceived::class, PurchaseCompleted::class]);
    $sync = app(RecordProviderResultAction::class);

    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_d', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']));

    $created = stripeResultFor('charge.dispute.created', stripeDispute('needs_response'));
    $sync->execute($created);

    expect($created->type())->toBe(ResultType::Refund)
        ->and($created->isChargeback())->toBeTrue()
        ->and($created->providerId())->toBe('dp_1')
        ->and($created->transactionId())->toBe('pi_d')
        ->and(Purchase::query()->sole()->status)->toBe(Status::Refunded);
    Event::assertDispatchedTimes(ChargebackReceived::class, 1);

    $updated = stripeResultFor('charge.dispute.updated', stripeDispute('under_review'));
    expect($updated->type())->toBe(ResultType::Notification)
        ->and($sync->execute($updated))->toBeNull();

    $won = stripeResultFor('charge.dispute.closed', stripeDispute('won'));
    $sync->execute($won);

    expect($won->type())->toBe(ResultType::Refund)
        ->and($won->isChargeback())->toBeTrue()
        ->and($won->providerId())->toBe('dp_1')
        ->and($won->status())->toBe(Status::Completed)
        ->and(Purchase::query()->sole()->status)->toBe(Status::Completed)
        ->and(Purchase::query()->sole()->price?->minor())->toBe('5000')
        ->and(PurchaseRefund::query()->count())->toBe(1);
    Event::assertDispatchedTimes(ChargebackReceived::class, 1);
    Event::assertDispatched(PurchaseCompleted::class);
});

it('records a lost dispute as the same single chargeback', function (): void {
    $sync = app(RecordProviderResultAction::class);
    $sync->execute(stripeResultFor('charge.dispute.created', stripeDispute('needs_response')));
    $sync->execute(stripeResultFor('charge.dispute.closed', stripeDispute('lost')));

    expect(PurchaseRefund::query()->sole()->provider_id)->toBe('dp_1')
        ->and(PurchaseRefund::query()->sole()->chargeback)->toBeTrue();
});

it('does not treat an inquiry as a chargeback', function (string $type, string $status): void {
    $sync = app(RecordProviderResultAction::class);
    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_d', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']));

    $result = stripeResultFor($type, stripeDispute($status));
    $sync->execute($result);

    expect($result->type())->toBe(ResultType::Notification)
        ->and(Purchase::query()->sole()->status)->toBe(Status::Completed)
        ->and(PurchaseRefund::query()->count())->toBe(0);
})->with([
    'inquiry opened' => ['charge.dispute.created', 'warning_needs_response'],
    'inquiry closed' => ['charge.dispute.closed', 'warning_closed'],
]);

it('does not link a refund and a dispute of one payment into one row', function (): void {
    $sync = app(RecordProviderResultAction::class);
    $sync->execute(stripeResultFor('charge.refunded', ['id' => 'ch_d', 'payment_intent' => 'pi_d', 'amount' => 5000, 'amount_refunded' => 1000, 'refunded' => false, 'currency' => 'eur']));
    $sync->execute(stripeResultFor('charge.dispute.created', stripeDispute('needs_response', ['amount' => 4000])));

    expect(PurchaseRefund::query()->count())->toBe(2)
        ->and(PurchaseRefund::query()->where('chargeback', false)->sole()->price?->minor())->toBe('1000');
});

it('keeps a won dispute it cannot tie to a payment on the dispute alone', function (): void {
    Event::fake([ChargebackReceived::class, PurchaseCompleted::class]);

    $result = stripeResultFor('charge.dispute.closed', stripeDispute('won', ['payment_intent' => null]));
    app(RecordProviderResultAction::class)->execute($result);

    expect($result->type())->toBe(ResultType::Refund)
        ->and(Purchase::query()->count())->toBe(0)
        ->and(PurchaseRefund::query()->sole()->provider_id)->toBe('dp_1');
    Event::assertNothingDispatched();
});

it('starts a subscription created incomplete once its first payment succeeds', function (): void {
    Event::fake([SubscriptionStarted::class, SubscriptionRenewed::class]);
    $sync = app(RecordProviderResultAction::class);

    // payment_behavior=default_incomplete, or a 3DS payment: created incomplete, then activated.
    $sync->execute(stripeResultFor('customer.subscription.created', stripeSubscriptionObject('incomplete')));

    expect(Subscription::query()->sole()->status)->toBe(Status::Pending);
    Event::assertNotDispatched(SubscriptionStarted::class);

    $sync->execute(stripeResultFor('customer.subscription.updated', stripeSubscriptionObject('active')));

    expect(Subscription::query()->sole()->status)->toBe(Status::Completed);
    Event::assertDispatchedTimes(SubscriptionStarted::class, 1);
    Event::assertNotDispatched(SubscriptionRenewed::class);
});

it('holds an unpaid or paused stripe subscription instead of expiring it', function (string $status): void {
    Event::fake([SubscriptionExpired::class]);

    $result = stripeResultFor('customer.subscription.updated', ['id' => 'sub_h', 'status' => $status]);
    app(RecordProviderResultAction::class)->execute($result);

    // Unpaid: invoices stay open and it can be paid back to active; paused: it resumes.
    expect($result->status())->toBe(Status::OnHold)
        ->and(Subscription::query()->sole()->status->isActive())->toBeFalse();

    Event::assertNotDispatched(SubscriptionExpired::class);
})->with(['unpaid', 'paused']);

it('reads the billing period from the subscription items on current api versions', function (): void {
    $result = stripeResultFor('customer.subscription.updated', [
        'id' => 'sub_p',
        'status' => 'active',
        'items' => ['object' => 'list', 'data' => [
            ['id' => 'si_1', 'current_period_start' => 1_780_000_000, 'current_period_end' => 1_782_592_000],
            ['id' => 'si_2', 'current_period_start' => 1_780_000_000, 'current_period_end' => 1_782_600_000],
        ]],
    ]);

    expect($result->activeFrom()?->getTimestamp())->toBe(1_780_000_000)
        ->and($result->endsAt()?->getTimestamp())->toBe(1_782_600_000);
});

it('reads an invoice\'s subscription from its parent on current api versions', function (): void {
    expect(Invoice::fromRaw(['id' => 'in_1', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_9']]])->subscription)->toBe('sub_9')
        ->and(Invoice::fromRaw(['id' => 'in_2', 'subscription' => 'sub_legacy'])->subscription)->toBe('sub_legacy');
});

it('leaves a renewal payment to its subscription on current api versions', function (): void {
    Event::fake([PurchaseCompleted::class]);
    stripeInvoicePayments('in_renewal');
    $sync = app(RecordProviderResultAction::class);

    // Since 2025-03-31 a PaymentIntent no longer names its invoice (no `invoice` key at all).
    foreach (['pi_r1', 'pi_r2'] as $id) {
        $result = stripeResultFor('payment_intent.succeeded', ['id' => $id, 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 999, 'currency' => 'eur']);

        expect($result->type())->toBe(ResultType::Notification)
            ->and($sync->execute($result))->toBeNull();
    }

    expect(Purchase::query()->count())->toBe(0);
    Event::assertNotDispatched(PurchaseCompleted::class);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.stripe.com/v1/invoice_payments')
        && $request['payment']['type'] === 'payment_intent'
        && $request['payment']['payment_intent'] === 'pi_r1'
        && $request->hasHeader('Stripe-Version', '2026-05-27.dahlia'));
});

it('records a one-off invoice once, not again through its payment', function (): void {
    Event::fake([PurchaseCompleted::class]);
    stripeInvoicePayments('in_once');
    $sync = app(RecordProviderResultAction::class);

    $sync->execute(stripeResultFor('invoice.paid', ['id' => 'in_once', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur']));
    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_once', 'status' => 'succeeded', 'amount' => 4500, 'currency' => 'eur']));

    expect(Purchase::query()->sole()->provider_id)->toBe('in_once');
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

/*
 * A one-off invoice is recorded from `invoice.paid`, keyed on the invoice — but its refund
 * and its dispute name the PaymentIntent that paid it. The purchase carries that
 * PaymentIntent as its transaction, so they link to it.
 */

it('links a refund of a one-off invoice to its purchase', function (): void {
    Event::fake([PurchaseRefunded::class]);
    stripeInvoicePayments('in_once', 'pi_once');
    $sync = app(RecordProviderResultAction::class);

    // API versions since 2025-03-31: the invoice names no PaymentIntent, Invoice Payments does.
    $sync->execute(stripeResultFor('invoice.paid', ['id' => 'in_once', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur']));
    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_once', 'status' => 'succeeded', 'amount' => 4500, 'currency' => 'eur']));
    $sync->execute(stripeResultFor('charge.refunded', ['id' => 'ch_once', 'payment_intent' => 'pi_once', 'amount' => 4500, 'amount_refunded' => 4500, 'refunded' => true, 'currency' => 'eur']));

    $purchase = Purchase::query()->sole();

    expect($purchase->provider_id)->toBe('in_once')
        ->and($purchase->transaction_id)->toBe('pi_once')
        ->and($purchase->status)->toBe(Status::Refunded)
        ->and(PurchaseRefund::query()->sole()->purchase_id)->toBe($purchase->getKey());
    Event::assertDispatched(PurchaseRefunded::class, fn (PurchaseRefunded $event): bool => $event->refund->purchase?->is($purchase) === true);
    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://api.stripe.com/v1/invoice_payments')
        && ($request->data()['invoice'] ?? null) === 'in_once'
        && ($request->data()['status'] ?? null) === 'paid');
});

it('links a dispute of a one-off invoice to its purchase', function (): void {
    Event::fake([ChargebackReceived::class]);
    stripeInvoicePayments('in_once', 'pi_once');
    $sync = app(RecordProviderResultAction::class);

    $sync->execute(stripeResultFor('invoice.paid', ['id' => 'in_once', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur']));
    $sync->execute(stripeResultFor('charge.dispute.created', stripeDispute('needs_response', ['payment_intent' => 'pi_once', 'amount' => 4500])));

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded)
        ->and(PurchaseRefund::query()->sole()->purchase_id)->toBe(Purchase::query()->sole()->getKey());
    Event::assertDispatchedTimes(ChargebackReceived::class, 1);
});

it('reads a legacy invoice\'s payment intent without asking stripe', function (): void {
    Http::fake();
    $sync = app(RecordProviderResultAction::class);

    // Before 2025-03-31 the invoice named its PaymentIntent, and the charge its invoice.
    $sync->execute(stripeResultFor('invoice.paid', ['id' => 'in_legacy', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur', 'payment_intent' => 'pi_legacy']));
    $sync->execute(stripeResultFor('charge.refunded', ['id' => 'ch_legacy', 'payment_intent' => 'pi_legacy', 'invoice' => 'in_legacy', 'amount' => 4500, 'amount_refunded' => 4500, 'refunded' => true, 'currency' => 'eur']));

    expect(Purchase::query()->sole()->transaction_id)->toBe('pi_legacy')
        ->and(Purchase::query()->sole()->status)->toBe(Status::Refunded)
        ->and(PurchaseRefund::query()->sole()->purchase_id)->toBe(Purchase::query()->sole()->getKey());
    Http::assertNothingSent();
});

it('keeps an invoice that no payment intent paid on the invoice', function (?string $legacy): void {
    $object = ['id' => 'in_free', 'status' => 'paid', 'amount_paid' => 0, 'billing_reason' => 'manual', 'currency' => 'eur'];

    // Paid out of band, or nothing was due: the legacy field is null, Invoice Payments lists none.
    $result = stripeResultFor('invoice.paid', $legacy === 'legacy' ? $object + ['payment_intent' => null] : $object);

    expect($result->providerId())->toBe('in_free')
        ->and($result->transactionId())->toBe('in_free');
})->with(['legacy' => ['legacy'], 'current' => [null]]);

it('takes the default of an invoice\'s partial payments', function (bool $withDefault, string $expected): void {
    Http::fake(['stripe.test/v1/invoice_payments*' => Http::response(['object' => 'list', 'data' => [
        ['id' => 'inpay_1', 'status' => 'paid', 'payment' => ['type' => 'out_of_band_payment']],
        ['id' => 'inpay_2', 'status' => 'paid', 'is_default' => false, 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_part']],
        ['id' => 'inpay_3', 'status' => 'paid', 'is_default' => $withDefault, 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_default']],
    ]])]);

    $stripe = new Stripe(client: new StripeClient('sk_test', 'https://stripe.test/v1', Stripe::API_VERSION));
    $payload = (string) json_encode(['id' => 'evt_part', 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_part', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur']]]);

    expect($stripe->result(signedWebhook($payload))->transactionId())->toBe($expected);
})->with([
    'a default payment' => [true, 'pi_default'],
    'no default payment' => [false, 'pi_part'],
]);

it('reads an expanded payment intent from a legacy invoice', function (): void {
    $result = stripeResultFor('invoice.paid', ['id' => 'in_exp', 'status' => 'paid', 'amount_paid' => 4500, 'billing_reason' => 'manual', 'currency' => 'eur', 'payment_intent' => ['id' => 'pi_exp', 'object' => 'payment_intent']]);

    expect($result->transactionId())->toBe('pi_exp');
});

it('records a payment that pays no invoice as a purchase', function (): void {
    stripeInvoicePayments(null);

    $result = stripeResultFor('payment_intent.payment_failed', ['id' => 'pi_alone', 'status' => 'requires_payment_method', 'amount' => 700, 'currency' => 'eur']);

    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->status())->toBe(Status::Failed);
});

it('reads the invoice from a legacy payment intent without asking stripe', function (): void {
    Http::fake();

    $result = stripeResultFor('payment_intent.succeeded', ['id' => 'pi_legacy', 'status' => 'succeeded', 'amount' => 700, 'currency' => 'eur', 'invoice' => null]);

    expect($result->type())->toBe(ResultType::Purchase);
    Http::assertNothingSent();
});

it('refuses to guess about a current payment intent without a secret key', function (): void {
    config()->set('purchases.settings.stripe.secret', null);

    stripeResultFor('payment_intent.succeeded', ['id' => 'pi_nokey', 'status' => 'succeeded', 'amount' => 700, 'currency' => 'eur']);
})->throws(VerificationException::class, 'Stripe secret key is not configured.');

it('never invents a purchase for a won dispute of a payment it did not record', function (): void {
    Event::fake([ChargebackReceived::class, PurchaseCompleted::class]);
    stripeInvoicePayments('in_sub');
    $sync = app(RecordProviderResultAction::class);

    // A subscription renewal's payment: audited, never a Purchase.
    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_sub', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']));
    $sync->execute(stripeResultFor('charge.dispute.created', stripeDispute('needs_response', ['payment_intent' => 'pi_sub'])));
    $sync->execute(stripeResultFor('charge.dispute.closed', stripeDispute('won', ['payment_intent' => 'pi_sub'])));

    expect(Purchase::query()->count())->toBe(0)
        ->and(PurchaseRefund::query()->sole()->provider_id)->toBe('dp_1');
    Event::assertDispatchedTimes(ChargebackReceived::class, 1);
    Event::assertNotDispatched(PurchaseCompleted::class);
});

it('reinstates a won dispute\'s purchase without replacing its payment data', function (): void {
    $sync = app(RecordProviderResultAction::class);

    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_d', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']));
    $sync->execute(stripeResultFor('charge.dispute.created', stripeDispute('needs_response')));
    $sync->execute(stripeResultFor('charge.dispute.closed', stripeDispute('won')));

    $purchase = Purchase::query()->sole();

    expect($purchase->status)->toBe(Status::Completed)
        ->and($purchase->meta['object'] ?? null)->toBe('payment_intent')
        ->and(PurchaseRefund::query()->sole()->meta['status'] ?? null)->toBe('won');
});

it('fires nothing for a won dispute redelivered after the purchase was reinstated', function (): void {
    $sync = app(RecordProviderResultAction::class);
    $sync->execute(stripeResultFor('payment_intent.succeeded', ['id' => 'pi_d', 'status' => 'succeeded', 'amount' => 5000, 'currency' => 'eur']));
    $sync->execute(stripeResultFor('charge.dispute.created', stripeDispute('needs_response')));
    $won = stripeResultFor('charge.dispute.closed', stripeDispute('won'));
    $sync->execute($won);

    Event::fake([ChargebackReceived::class, PurchaseCompleted::class, PurchaseRefunded::class]);
    $sync->execute($won);

    Event::assertNothingDispatched();
});

/**
 * @return array<string, mixed>
 */
function stripeSubscriptionObject(string $status = 'active', string $product = 'prod_pro'): array
{
    return [
        'id' => 'sub_9',
        'object' => 'subscription',
        'status' => $status,
        'items' => ['object' => 'list', 'data' => [[
            'id' => 'si_9',
            'current_period_start' => Carbon::now()->subDay()->getTimestamp(),
            'current_period_end' => Carbon::now()->addMonth()->getTimestamp(),
            'price' => ['id' => 'price_9', 'product' => $product],
        ]]],
    ];
}

it('names a new stripe subscription after its product', function (): void {
    $result = stripeResultFor('customer.subscription.created', stripeSubscriptionObject());
    app(RecordProviderResultAction::class)->execute($result);

    expect($result->productId())->toBe('prod_pro')
        ->and($result->name())->toBeNull()
        ->and(Subscription::query()->sole()->name)->toBe('prod_pro');
});

it('keeps the name the host gave a stripe subscription', function (): void {
    $sync = app(RecordProviderResultAction::class);
    $sync->execute(stripeResultFor('customer.subscription.created', stripeSubscriptionObject()));

    $user = User::query()->create(['name' => 'Ada']);
    $subscription = ownedBy($user, Subscription::query()->sole());
    $subscription->update(['name' => 'pro']);

    $sync->execute(stripeResultFor('customer.subscription.updated', stripeSubscriptionObject(product: 'prod_other')));

    expect(Subscription::query()->sole()->name)->toBe('pro')
        ->and($user->subscribedTo('pro'))->toBeTrue();
});

it('reads the product of an expanded stripe price', function (): void {
    $object = stripeSubscriptionObject();
    $object['items']['data'][0]['price']['product'] = ['id' => 'prod_expanded', 'object' => 'product'];

    expect(stripeResultFor('customer.subscription.updated', $object)->productId())->toBe('prod_expanded');
});
