<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchase;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseItem;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseNotification;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchaseRefund;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomSubscription;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomSubscriptionItem;

/*
 * A factory names its packaged model in `$model`, so without a `modelName()` override that
 * returns the seam it keeps building the packaged class after a host swapped it — and the
 * host's events, observers and casts never run on factory-made rows.
 */
it('builds the configured purchase from the factory', function (): void {
    expect(Purchase::factory()->create()::class)->toBe(CustomPurchase::class);
});

it('builds the configured purchase item and its parent from the factory', function (): void {
    $item = PurchaseItem::factory()->create();

    expect($item::class)->toBe(CustomPurchaseItem::class)
        ->and($item->purchase()->firstOrFail()::class)->toBe(CustomPurchase::class);
});

it('builds the configured purchase refund from the factory', function (): void {
    expect(PurchaseRefund::factory()->create()::class)->toBe(CustomPurchaseRefund::class);
});

it('builds the configured purchase notification from the factory', function (): void {
    expect(PurchaseNotification::factory()->create()::class)->toBe(CustomPurchaseNotification::class);
});

it('builds the configured subscription from the factory', function (): void {
    expect(Subscription::factory()->create()::class)->toBe(CustomSubscription::class);
});

it('builds the configured subscription item from the factory', function (): void {
    expect(SubscriptionItem::factory()->create()::class)->toBe(CustomSubscriptionItem::class);
});
