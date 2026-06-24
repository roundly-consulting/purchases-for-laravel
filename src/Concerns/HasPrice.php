<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use RoundlyConsulting\Purchases\ValueObjects\Money;

trait HasPrice
{
    /**
     * Expose the `price` / `price_currency` columns as a single Money value object.
     *
     * Assigning a Money writes both columns. Assigning a raw integer (e.g. from a
     * factory or seeder) writes the amount and leaves the currency column untouched.
     *
     * @return Attribute<?Money, Money|int|null>
     */
    protected function price(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value, array $attributes): ?Money {
                $amount = $attributes['price'] ?? null;
                $currency = $attributes['price_currency'] ?? null;

                if ($amount === null || $currency === null) {
                    return null;
                }

                return new Money((int) $amount, (string) $currency);
            },
            set: function (Money|int|null $value): array {
                if ($value instanceof Money) {
                    return [
                        'price' => $value->amount,
                        'price_currency' => $value->currency,
                    ];
                }

                return ['price' => $value];
            },
        );
    }
}
