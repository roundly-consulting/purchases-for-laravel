<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Every priced model, built through its factory (parents created on demand).
 *
 * @return array<string, array{0: Closure(array<string, mixed>): Model}>
 */
function pricedModels(): array
{
    return [
        'purchase' => [fn (array $attributes = []): Model => Purchase::factory()->create($attributes)],
        'purchase item' => [fn (array $attributes = []): Model => PurchaseItem::factory()->create($attributes)],
        'purchase refund' => [fn (array $attributes = []): Model => PurchaseRefund::factory()->create($attributes)],
        'subscription' => [fn (array $attributes = []): Model => Subscription::factory()->create($attributes)],
        'subscription item' => [fn (array $attributes = []): Model => SubscriptionItem::factory()->create($attributes)],
    ];
}

it('creates every priced model through its factory with a money price', function (Closure $create): void {
    $model = $create()->refresh();

    expect($model->getAttribute('price'))->toBeInstanceOf(Money::class)
        ->and($model->getAttribute('price_currency'))->toBe('USD');
})->with(pricedModels());

it('round-trips the price through both columns', function (Closure $create): void {
    $model = $create(['price' => Money::ofMinor(1999, 'EUR')])->refresh();

    /** @var Money $price */
    $price = $model->getAttribute('price');

    expect($price->minor())->toBe('1999')
        ->and($price->currency()->code)->toBe('EUR')
        ->and($model->getAttribute('price_currency'))->toBe('EUR');
})->with(pricedModels());

it('stores a null price and keeps the currency column', function (Closure $create): void {
    $model = $create(['price' => Money::ofMinor(500, 'GBP')]);

    $model->setAttribute('price', null);
    $model->save();
    $model->refresh();

    expect($model->getAttribute('price'))->toBeNull()
        ->and($model->getAttribute('price_currency'))->toBe('GBP');
})->with(pricedModels());

it('refuses a raw integer price', function (Closure $create): void {
    $create(['price' => 1999]);
})->with(pricedModels())->throws(InvalidMoneyValue::class);

it('refuses an amount stored without its currency', function (): void {
    $purchase = Purchase::factory()->create();

    DB::table($purchase->getTable())->where('id', $purchase->getKey())->update(['price_currency' => null]);

    $purchase->refresh()->getAttribute('price');
})->throws(InvalidMoneyValue::class, 'currency column [price_currency] is null');

it('keeps zero-decimal and three-decimal currencies exact', function (): void {
    $yen = Purchase::factory()->create(['price' => Money::ofMinor(500, 'JPY')])->refresh();
    $dinar = Purchase::factory()->create(['price' => Money::ofMinor(1995, 'BHD')])->refresh();

    expect($yen->price?->toDecimal())->toBe('500')
        ->and($dinar->price?->toDecimal())->toBe('1.995');
});

it('stores an amount beyond 32 bits', function (): void {
    $purchase = Purchase::factory()->create(['price' => Money::ofMinor('9000000000123', 'USD')])->refresh();

    expect($purchase->price?->minor())->toBe('9000000000123');
});

it('stores an amount beyond 64 bits on pgsql', function (): void {
    $purchase = Purchase::factory()->create(['price' => Money::ofMinor('123456789012345678901234567890', 'USD')])->refresh();

    expect($purchase->price?->minor())->toBe('123456789012345678901234567890')
        ->and(Purchase::query()->where('price', '>', '99999999999999999999')->count())->toBe(1);
})->skip(fn (): bool => DriverMatrix::driver() !== 'pgsql', 'needs a real decimal engine');

it('refuses an amount beyond 64 bits on sqlite instead of storing a float', function (): void {
    Purchase::factory()->create(['price' => Money::ofMinor('123456789012345678901234567890', 'USD')]);
})->throws(InvalidMoneyValue::class)
    ->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'sqlite range guard');
