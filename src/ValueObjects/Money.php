<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;
use NumberFormatter;
use RoundlyConsulting\Purchases\Exceptions\CurrencyMismatchException;
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

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public static function of(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function times(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
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

    /**
     * Format the amount as a localized currency string. Uses ext-intl when present,
     * and falls back to a plain minor-unit-aware format otherwise.
     */
    public function format(?string $locale = null): string
    {
        $locale ??= 'en_US';

        if (extension_loaded('intl')) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

            return (string) $formatter->formatCurrency($this->amount / 100, $this->currency);
        }

        return number_format($this->amount / 100, 2).' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->isSameCurrency($other)) {
            throw CurrencyMismatchException::because(
                "Cannot operate on [{$this->currency}] and [{$other->currency}] amounts.",
            );
        }
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
