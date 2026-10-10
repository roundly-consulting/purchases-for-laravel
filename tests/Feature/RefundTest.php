<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Actions\RecordRefundAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\ChargebackReceived;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Results\GenericResult;

it('records a refund and flips the related purchase status', function (): void {
    $purchase = Purchase::factory()->create([
        'provider' => 'stripe',
        'provider_id' => 'pi_1',
        'transaction_id' => 'pi_1',
        'status' => Status::Completed,
    ]);

    $refund = app(RecordRefundAction::class)->execute(new RecordRefundData(
        provider: 'stripe',
        providerId: 're_1',
        transactionId: 'pi_1',
        reason: 'requested_by_customer',
        price: Money::ofMinor(999, 'USD'),
    ));

    expect($refund)->toBeInstanceOf(PurchaseRefund::class)
        ->and($refund->purchase_id)->toBe($purchase->getKey())
        ->and($refund->price?->minor())->toBe('999')
        ->and($purchase->refresh()->status)->toBe(Status::Refunded)
        ->and($purchase->refunds()->count())->toBe(1);
});

it('records a refund without a matching purchase', function (): void {
    $refund = app(RecordRefundAction::class)->execute(new RecordRefundData(
        provider: 'stripe',
        providerId: 're_orphan',
    ));

    expect($refund->purchase_id)->toBeNull();
});

it('is idempotent on provider and provider id', function (): void {
    $data = new RecordRefundData(provider: 'stripe', providerId: 're_2');

    $first = app(RecordRefundAction::class)->execute($data);
    $second = app(RecordRefundAction::class)->execute($data);

    expect($first->getKey())->toBe($second->getKey())
        ->and(PurchaseRefund::query()->count())->toBe(1);
});

it('dispatches the refunded event for a voluntary refund', function (): void {
    Event::fake([PurchaseRefunded::class, ChargebackReceived::class]);

    $result = new GenericResult(
        provider: 'stripe',
        type: ResultType::Refund,
        providerId: 're_3',
        status: Status::Refunded,
        chargeback: false,
    );

    app(RecordProviderResultAction::class)->execute($result);

    Event::assertDispatched(PurchaseRefunded::class);
    Event::assertNotDispatched(ChargebackReceived::class);
});

it('dispatches the chargeback event for a dispute', function (): void {
    Event::fake([PurchaseRefunded::class, ChargebackReceived::class]);

    $result = new GenericResult(
        provider: 'stripe',
        type: ResultType::Refund,
        providerId: 're_4',
        status: Status::Refunded,
        chargeback: true,
    );

    app(RecordProviderResultAction::class)->execute($result);

    Event::assertDispatched(ChargebackReceived::class);
    Event::assertNotDispatched(PurchaseRefunded::class);
});

it('scopes refunds to chargebacks', function (): void {
    PurchaseRefund::factory()->create();
    PurchaseRefund::factory()->chargeback()->create();

    expect(PurchaseRefund::chargebacks()->count())->toBe(1);
});

it('revokes a subscription only when its current period is refunded', function (string $order, Status $expected): void {
    Subscription::factory()->create([
        'provider' => 'google',
        'provider_id' => 'token-sub',
        'transaction_id' => 'GPA.1..1',
        'status' => Status::Completed,
    ]);

    // A Google voided-purchase notification is keyed on the voided order.
    app(RecordRefundAction::class)->execute(new RecordRefundData(provider: 'google', providerId: $order, transactionId: $order));

    expect(Subscription::query()->sole()->status)->toBe($expected);
})->with([
    'the latest order' => ['GPA.1..1', Status::Refunded],
    'an earlier renewal order' => ['GPA.1..0', Status::Completed],
]);

/*
 * A refund's date: Apple names it (`revocationDate`); Stripe and Google say only when the
 * refund event happened, which is when the refund happened.
 */

it('dates a stripe refund by its event', function (): void {
    config()->set('purchases.settings.stripe.webhook_secret', 'whsec_test');

    $result = (new Stripe)->result(stripeSignedRequest([
        'id' => 'evt_refunded',
        'type' => 'charge.refunded',
        'created' => 1_700_000_500,
        'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'amount' => 999, 'amount_refunded' => 999, 'refunded' => true, 'currency' => 'eur']],
    ]));

    app(RecordProviderResultAction::class)->execute($result);

    expect(PurchaseRefund::query()->sole()->refunded_at?->getTimestamp())->toBe(1_700_000_500);
});

it('dates a voided google purchase by its notification', function (): void {
    config()->set('purchases.settings.google.package_name', 'com.example.app');
    config()->set('purchases.settings.google.push', ['authenticate' => false]);

    $result = (new Google)->result(new Request(['message' => ['data' => base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000600000',
        'voidedPurchaseNotification' => ['purchaseToken' => 'tok-v', 'orderId' => 'GPA.V-1', 'productType' => 2, 'refundType' => 1],
    ]))]]));

    app(RecordProviderResultAction::class)->execute($result);

    expect(PurchaseRefund::query()->sole()->refunded_at?->getTimestamp())->toBe(1_700_000_600);
});

it('keeps the date a provider gives the refund itself', function (): void {
    app(RecordProviderResultAction::class)->execute(new GenericResult(
        provider: 'apple',
        type: ResultType::Refund,
        providerId: 'txn-1',
        status: Status::Refunded,
        endsAt: Carbon::createFromTimestamp(1_700_000_100),
        occurredAt: Carbon::createFromTimestamp(1_700_000_900),
    ));

    expect(PurchaseRefund::query()->sole()->refunded_at?->getTimestamp())->toBe(1_700_000_100);
});
