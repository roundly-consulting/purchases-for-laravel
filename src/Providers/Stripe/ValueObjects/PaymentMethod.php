<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A Stripe payment method — the customer's default one, from
 * `Purchases::stripe()->customers()->defaultPaymentMethod()` — for a billing page's
 * "Visa ••4242, expires 08/27".
 *
 * `type` is Stripe's (`card`, `sepa_debit`, `us_bank_account`, …). A card fills `brand` (lower
 * case, as Stripe sends it: `visa`, `mastercard`, `amex`), `last4` and the expiry; any other
 * type leaves `brand` and the expiry null and takes `last4` from its own object when it has one
 * (`sepa_debit.last4`, `us_bank_account.last4`). Everything else Stripe sent is in `raw`.
 *
 * @link https://docs.stripe.com/api/payment_methods/object
 */
final readonly class PaymentMethod implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $brand,
        public ?string $last4,
        public ?int $expMonth,
        public ?int $expYear,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $type = self::string($dataset->value('type')) ?? 'unknown';
        $card = $type === 'card';

        return new self(
            id: (string) $dataset->value('id'),
            type: $type,
            brand: $card ? self::string($dataset->value('card.brand')) : null,
            last4: self::string($dataset->value($type.'.last4')),
            expMonth: $card ? self::month($dataset->value('card.exp_month')) : null,
            expYear: $card ? self::year($dataset->value('card.exp_year')) : null,
            raw: $raw,
        );
    }

    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    /**
     * Whether the card's expiry month has ended at `$at` (now by default). A card is good through
     * the last day of its expiry month; a method without an expiry never expires.
     */
    public function isExpired(?CarbonInterface $at = null): bool
    {
        $expires = $this->expiresAt();

        return $expires !== null && ! CarbonImmutable::instance($at ?? CarbonImmutable::now())->utc()->lessThan($expires);
    }

    /**
     * The first moment the card no longer works — the start of the month after its expiry month,
     * UTC — or null for a method without an expiry.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        if ($this->expMonth === null || $this->expYear === null) {
            return null;
        }

        return CarbonImmutable::create($this->expYear, $this->expMonth, 1, 0, 0, 0, 'UTC')?->addMonthNoOverflow();
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function month(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= 12 ? $value : null;
    }

    private static function year(mixed $value): ?int
    {
        return is_int($value) && $value >= 1970 && $value <= 9999 ? $value : null;
    }
}
