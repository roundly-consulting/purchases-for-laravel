<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\RecordPurchaseAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;

it('creates a purchase from result data', function (): void {
    $purchase = (new RecordPurchaseAction)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'pi_1',
        status: Status::Completed,
        price: Money::ofMinor(1999, 'USD'),
        meta: ['id' => 'pi_1'],
    ));

    expect($purchase->provider)->toBe('stripe')
        ->and($purchase->provider_id)->toBe('pi_1')
        ->and($purchase->status)->toBe(Status::Completed)
        ->and($purchase->price?->minor())->toBe('1999');

    expect(Purchase::query()->count())->toBe(1);
});

it('updates rather than duplicates on the same provider id', function (): void {
    $action = new RecordPurchaseAction;

    $action->execute(new RecordPurchaseData('stripe', 'pi_1', Status::Pending));
    $action->execute(new RecordPurchaseData('stripe', 'pi_1', Status::Completed));

    expect(Purchase::query()->count())->toBe(1)
        ->and(Purchase::query()->first()?->status)->toBe(Status::Completed);
});

it('syncs purchase items', function (): void {
    $purchase = (new RecordPurchaseAction)->execute(new RecordPurchaseData(
        provider: 'stripe',
        providerId: 'pi_1',
        status: Status::Completed,
        items: [
            new ResultItem(name: 'Pro', providerId: 'sku', price: Money::ofMinor(500, 'USD'), quantity: 2),
        ],
    ));

    expect($purchase->items()->count())->toBe(1)
        ->and($purchase->items()->first()?->name)->toBe('Pro')
        ->and($purchase->items()->first()?->quantity)->toBe(2);
});
