<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Support\PurchaseItemModel;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;
use RoundlyConsulting\Purchases\Support\SubscriptionItemModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;
use RoundlyConsulting\Purchases\Tests\Fixtures\Models\CustomPurchase;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

/**
 * Every `purchases.models.*` key resolves through exactly one place. The toolkit's
 * ModelResolver validates that the configured value is a real Eloquent model — it
 * cannot know whether it is *ours*, so each wrapper narrows on top of it.
 *
 * @return iterable<string, array{string, class-string, class-string}>
 */
dataset('configurable models', [
    'purchase' => ['purchases.models.purchase', PurchaseModel::class, Purchase::class],
    'purchase item' => ['purchases.models.purchase-item', PurchaseItemModel::class, PurchaseItem::class],
    'purchase refund' => ['purchases.models.purchase-refund', PurchaseRefundModel::class, PurchaseRefund::class],
    'purchase notification' => ['purchases.models.purchase-notification', PurchaseNotificationModel::class, PurchaseNotification::class],
    'subscription' => ['purchases.models.subscription', SubscriptionModel::class, Subscription::class],
    'subscription item' => ['purchases.models.subscription-item', SubscriptionItemModel::class, SubscriptionItem::class],
]);

it('resolves the packaged model by default', function (string $key, string $resolver, string $packaged): void {
    expect($resolver::class())->toBe($packaged)
        ->and($resolver::new())->toBeInstanceOf($packaged)
        ->and($resolver::query()->getModel())->toBeInstanceOf($packaged);
})->with('configurable models');

it('honours a host subclass', function (): void {
    config()->set('purchases.models.purchase', CustomPurchase::class);

    expect(PurchaseModel::class())->toBe(CustomPurchase::class)
        ->and(PurchaseModel::new())->toBeInstanceOf(CustomPurchase::class)
        ->and(PurchaseModel::query()->getModel())->toBeInstanceOf(CustomPurchase::class);
});

/**
 * The toolkit validates is-a-*Model*, never is-a-*ours*. A host that points a key at
 * a real Eloquent model of its own gets a class that cannot answer the package's
 * casts, scopes or relations — so the wrapper falls back rather than handing back a
 * model the rest of the package would crash on.
 */
it('falls back to the packaged model for a real model that is not ours', function (string $key, string $resolver, string $packaged): void {
    config()->set($key, User::class);

    expect($resolver::class())->toBe($packaged);
})->with('configurable models');

it('fails loudly when the configured value is not a model class at all', function (string $key, string $resolver): void {
    config()->set($key, 'Definitely\Not\A\Class');

    expect(static fn (): string => $resolver::class())
        ->toThrow(InvalidConfigurationException::class);
})->with('configurable models');
