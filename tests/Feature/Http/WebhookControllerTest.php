<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

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
