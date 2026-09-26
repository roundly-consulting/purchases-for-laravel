<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\EventType;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentIntentStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

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

beforeEach(fn () => configureStripe());

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
        ->and($result->providerId())->toBe('pi_dispute');
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
        ->and((new SyncProviderResultAction)->execute($result))->toBeNull()
        ->and(Subscription::query()->count())->toBe(0)
        ->and(Purchase::query()->count())->toBe(0);
});
