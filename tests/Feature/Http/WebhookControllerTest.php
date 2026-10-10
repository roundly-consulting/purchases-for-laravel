<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Testing\PayloadFactory;

beforeEach(function (): void {
    config()->set('purchases.routes', [
        'enabled' => true,
        'prefix' => 'purchases',
        'middleware' => [],
    ]);

    config()->set('purchases.providers', [
        Stripe::class,
    ]);

    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);

    require __DIR__.'/../../../routes/purchases.php';
});

afterEach(fn () => Carbon::setTestNow());

function stripeWebhookHeaders(string $payload): array
{
    $timestamp = Carbon::now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

    return ['Stripe-Signature' => "t={$timestamp},v1={$signature}"];
}

it('records a purchase from a valid signed webhook', function (): void {
    Event::fake();
    stripeInvoicePayments(null);

    $payload = (string) json_encode([
        'id' => 'evt_1',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_1', 'status' => 'succeeded', 'amount' => 1000, 'currency' => 'usd']],
    ]);

    $response = $this->call('POST', '/purchases/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars(stripeWebhookHeaders($payload)), $payload);

    $response->assertNoContent();

    expect(Purchase::query()->where('provider_id', 'pi_1')->exists())->toBeTrue();
    Event::assertDispatched(PurchaseCompleted::class);
});

it('rejects a webhook with an invalid signature', function (): void {
    $payload = '{"id":"evt_1","type":"payment_intent.succeeded","data":{"object":{}}}';

    $response = $this->call('POST', '/purchases/webhooks/stripe', [], [], [], $this->transformHeadersToServerVars(['Stripe-Signature' => 't=1,v1=bad']), $payload);

    $response->assertStatus(400);
});

it('returns 404 for an unknown provider', function (): void {
    $response = $this->call('POST', '/purchases/webhooks/paypal', [], [], [], [], '{}');

    $response->assertNotFound();
});

it('does not register routes when disabled', function (): void {
    config()->set('purchases.routes.enabled', false);

    expect(config('purchases.routes.enabled'))->toBeFalse();
});

it('answers 2xx to another app\'s google notification, recording nothing', function (): void {
    config()->set('purchases.providers', [Google::class]);
    config()->set('purchases.settings.google.package_name', 'com.example.app');
    config()->set('purchases.settings.google.push', ['authenticate' => false]);

    $response = $this->postJson('/purchases/webhooks/google', PayloadFactory::googleEnvelope([
        'version' => '1.0',
        'packageName' => 'com.other.app',
        'eventTimeMillis' => '1700000000000',
        'voidedPurchaseNotification' => ['purchaseToken' => 'tok-other', 'orderId' => 'GPA.OTHER-1', 'refundType' => 1],
    ]));

    $response->assertNoContent();

    expect(PurchaseRefund::query()->count())->toBe(0)
        ->and(PurchaseNotification::query()->sole()->type)->toBe('notification');
});

it('refuses an apple notification whose signed payload is not a string', function (mixed $signedPayload): void {
    config()->set('purchases.providers', [Apple::class]);
    config()->set('purchases.settings.apple.bundle_id', 'com.example.app');

    $this->postJson('/purchases/webhooks/apple', $signedPayload === 'missing' ? [] : ['signedPayload' => $signedPayload])
        ->assertBadRequest();
})->with([
    'an array' => [['x']],
    'an integer' => [5],
    'missing' => ['missing'],
    'empty' => [''],
    'not a jws' => ['x'],
]);
