<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use RoundlyConsulting\Purchases\Exceptions\InvalidMoneyException;

/**
 * Minimal, dependency-free money value object: an integer amount expressed in the
 * currency's minor unit (e.g. cents) plus an ISO 4217 currency code.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class Money implements Arrayable
{
    public string $currency;

    public function __construct(
        public int $amount,
        string $currency,
    ) {
        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw InvalidMoneyException::because("Invalid currency code [{$currency}]; expected a 3-letter ISO 4217 code.");
        }

        $this->currency = $currency;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    /** @return array{amount: int, currency: string} */
    public function toArray(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
