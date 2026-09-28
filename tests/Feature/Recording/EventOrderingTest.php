<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Events\SubscriptionCanceled;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\PurchaseModel;

/*
 | Stores deliver at least once and in no particular order, and the audit log replays.
 | None of that may move a purchase or subscription backwards, or fulfil an order twice.
 */

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.webhook_secret', 'whsec_test');
    Event::fake([PurchaseCompleted::class, PurchaseRefunded::class, SubscriptionRenewed::class, SubscriptionCanceled::class]);
});

/** @return array<string, mixed> */
function orderedPaymentSucceeded(): array
{
    return ['id' => 'evt_paid', 'type' => 'payment_intent.succeeded', 'created' => 1_700_000_100, 'data' => ['object' => [
        'id' => 'pi_order', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 2599, 'currency' => 'usd', 'invoice' => null,
    ]]];
}

/** @return array<string, mixed> */
function orderedChargeRefunded(int $amountRefunded = 2599, int $created = 1_700_000_200, string $id = 'evt_refunded'): array
{
    return ['id' => $id, 'type' => 'charge.refunded', 'created' => $created, 'data' => ['object' => [
        'id' => 'ch_order', 'object' => 'charge', 'payment_intent' => 'pi_order', 'amount' => 2599,
        'amount_refunded' => $amountRefunded, 'refunded' => $amountRefunded === 2599, 'currency' => 'usd',
    ]]];
}

function refundOrder(): void
{
    Purchases::handle('stripe', stripeSignedRequest(orderedPaymentSucceeded()));
    Purchases::handle('stripe', stripeSignedRequest(orderedChargeRefunded()));

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
}

it('keeps a refunded purchase refunded when the payment event is redelivered', function (): void {
    refundOrder();

    Purchases::handle('stripe', stripeSignedRequest(orderedPaymentSucceeded()));

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('keeps a refunded purchase refunded when its old payment notification is replayed', function (): void {
    refundOrder();

    Purchases::replay(PurchaseNotification::query()->orderBy('id')->firstOrFail());

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('replays the whole audit log without moving anything backwards or fulfilling twice', function (): void {
    refundOrder();

    Artisan::call('purchases:replay');

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
    Event::assertDispatchedTimes(PurchaseRefunded::class, 1);
});

it('never un-refunds a purchase on a result that cannot say when it happened', function (): void {
    refundOrder();

    Purchases::sync(new GenericResult(provider: 'stripe', type: ResultType::Purchase, providerId: 'pi_order', status: Status::Completed));

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});

it('reinstates a refunded purchase on a provably newer event', function (): void {
    refundOrder();

    Purchases::sync(new GenericResult(
        provider: 'stripe',
        type: ResultType::Purchase,
        providerId: 'pi_order',
        status: Status::Completed,
        occurredAt: Carbon::createFromTimestamp(1_700_000_300),
    ));

    expect(Purchase::query()->sole()->status)->toBe(Status::Completed)
        ->and(Purchase::query()->sole()->last_event_at?->getTimestamp())->toBe(1_700_000_300);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 2);
});

it('does not shrink a cumulative refund on an older partial-refund event', function (): void {
    Purchases::handle('stripe', stripeSignedRequest(orderedPaymentSucceeded()));
    Purchases::handle('stripe', stripeSignedRequest(orderedChargeRefunded(1000, 1_700_000_200, 'evt_partial_1')));
    Purchases::handle('stripe', stripeSignedRequest(orderedChargeRefunded(1500, 1_700_000_300, 'evt_partial_2')));

    // Stripe redelivers the first partial refund after the second.
    Purchases::handle('stripe', stripeSignedRequest(orderedChargeRefunded(1000, 1_700_000_200, 'evt_partial_1')));

    expect(PurchaseRefund::query()->sole()->price?->minor())->toBe('1500');
    Event::assertDispatchedTimes(PurchaseRefunded::class, 2);
});

/** @return array<string, mixed> */
function orderedSubscriptionEvent(string $status, int $created, int $periodEnd): array
{
    return ['id' => 'evt_sub_'.$created, 'type' => 'customer.subscription.updated', 'created' => $created, 'data' => ['object' => [
        'id' => 'sub_order', 'object' => 'subscription', 'status' => $status,
        'items' => ['data' => [['current_period_start' => $periodEnd - 2_592_000, 'current_period_end' => $periodEnd]]],
    ]]];
}

it('does not revive a canceled subscription on an older out-of-order update', function (): void {
    $periodEnd = Carbon::now()->addMonth()->getTimestamp();

    Purchases::handle('stripe', stripeSignedRequest(orderedSubscriptionEvent('active', 1_700_000_100, $periodEnd)));
    Purchases::handle('stripe', stripeSignedRequest(orderedSubscriptionEvent('canceled', 1_700_000_300, $periodEnd)));

    // The earlier "active" update arrives last.
    Purchases::handle('stripe', stripeSignedRequest(orderedSubscriptionEvent('active', 1_700_000_200, $periodEnd)));

    expect(Subscription::query()->sole()->status)->toBe(Status::Canceled);
    Event::assertDispatchedTimes(SubscriptionCanceled::class, 1);
    Event::assertNotDispatched(SubscriptionRenewed::class);
});

it('applies a delivery that lost a creation race as an update', function (): void {
    // Another worker commits the same purchase right after this one looked for it and
    // found nothing: the unique index refuses this insert, and the event is applied to
    // the row that won instead of failing the webhook.
    $model = PurchaseModel::class();
    $raced = false;

    PurchaseModel::new()->getConnection()->listen(function (QueryExecuted $query) use ($model, &$raced): void {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '"purchases"')) {
            return;
        }

        $raced = true;
        $model::query()->create([
            'provider' => 'stripe',
            'provider_id' => 'pi_order',
            'status' => Status::Pending,
            'last_event_at' => Carbon::createFromTimestamp(1_700_000_000),
        ]);
    });

    Purchases::handle('stripe', stripeSignedRequest(orderedPaymentSucceeded()));

    expect($raced)->toBeTrue()
        ->and(Purchase::query()->sole()->status)->toBe(Status::Completed)
        ->and(Purchase::query()->sole()->last_event_at?->getTimestamp())->toBe(1_700_000_100);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
});
