<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Subscription;
use RoundlyConsulting\Purchases\SubscriptionItem;
use RoundlyConsulting\Purchases\ValueObjects\Money;

it('creates a subscription with date casts', function (): void {
    $subscription = Subscription::factory()->create([
        'active_from' => '2026-01-01 00:00:00',
    ]);

    expect($subscription->active_from)->toBeInstanceOf(CarbonInterface::class)
        ->and($subscription->meta)->toBeInstanceOf(Collection::class);
});

it('exposes the subscription price as money', function (): void {
    $subscription = Subscription::factory()->create([
        'price' => 999,
        'price_currency' => 'GBP',
    ]);

    expect($subscription->refresh()->price)->toBeInstanceOf(Money::class)
        ->and($subscription->price->currency)->toBe('GBP');
});

it('has many subscription items', function (): void {
    $subscription = Subscription::factory()->create();
    SubscriptionItem::factory()->forSubscription($subscription)->count(2)->create();

    expect($subscription->items)->toHaveCount(2)
        ->and($subscription->items->first())->toBeInstanceOf(SubscriptionItem::class);
});

it('relates an item back to its subscription', function (): void {
    $subscription = Subscription::factory()->create();
    $item = SubscriptionItem::factory()->forSubscription($subscription)->create();

    expect($item->subscription->is($subscription))->toBeTrue();
});

it('soft deletes a subscription', function (): void {
    $subscription = Subscription::factory()->create();

    $subscription->delete();

    expect(Subscription::withTrashed()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(0);
});

it('morphs to an owner', function (): void {
    $owner = Subscription::factory()->create();
    $subscription = Subscription::factory()->create([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
    ]);

    expect($subscription->owner->is($owner))->toBeTrue();
});
