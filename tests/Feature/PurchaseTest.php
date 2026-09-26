<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;

it('creates a purchase with its factory and casts status', function (): void {
    $purchase = Purchase::factory()->create([
        'status' => Status::Completed->value,
    ]);

    expect($purchase->status)->toBe(Status::Completed)
        ->and($purchase->meta)->toBeInstanceOf(Collection::class);
});

it('exposes the price columns as a money value object', function (): void {
    $purchase = Purchase::factory()->create([
        'price' => Money::ofMinor(2599, 'USD'),
    ]);

    $purchase->refresh();

    expect($purchase->price)->toBeInstanceOf(Money::class)
        ->and($purchase->price->minor())->toBe('2599')
        ->and($purchase->price->currency()->code)->toBe('USD');
});

it('returns a null price when no amount is stored', function (): void {
    $purchase = Purchase::factory()->create([
        'price' => null,
    ]);

    expect($purchase->refresh()->price)->toBeNull();
});

it('writes both columns when set with a money object', function (): void {
    $purchase = Purchase::factory()->create();

    $purchase->price = Money::ofMinor(4200, 'EUR');
    $purchase->save();

    expect($purchase->getAttributes()['price'])->toBe('4200')
        ->and($purchase->getAttributes()['price_currency'])->toBe('EUR');
});

it('has many items', function (): void {
    $purchase = Purchase::factory()->create();
    PurchaseItem::factory()->forPurchase($purchase)->count(3)->create();

    expect($purchase->items)->toHaveCount(3)
        ->and($purchase->items->first())->toBeInstanceOf(PurchaseItem::class);
});

it('belongs to its purchase from an item', function (): void {
    $purchase = Purchase::factory()->create();
    $item = PurchaseItem::factory()->forPurchase($purchase)->create();

    expect($item->purchase->is($purchase))->toBeTrue();
});

it('soft deletes a purchase', function (): void {
    $purchase = Purchase::factory()->create();

    $purchase->delete();

    expect(Purchase::query()->count())->toBe(0)
        ->and(Purchase::withTrashed()->count())->toBe(1);
});

it('morphs to an owner', function (): void {
    $owner = Purchase::factory()->create();
    $purchase = Purchase::factory()->create([
        'owner_type' => $owner->getMorphClass(),
        'owner_id' => $owner->getKey(),
    ]);

    expect($purchase->owner->is($owner))->toBeTrue();
});
