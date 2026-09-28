<?php

declare(strict_types=1);

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
