<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * The last failed payment attempt of a customer, from
 * `Purchases::stripe()->invoices()->lastPaymentFailure()` — for "Payment failed on Nov 3:
 * card_declined" next to an open invoice.
 *
 * Read off a PaymentIntent's `last_payment_error`: `code` (`card_declined`,
 * `expired_card`, …), `declineCode` (the issuer's reason, `insufficient_funds`, … — cards only),
 * `message` (Stripe's, in English; show your own copy to customers). `at` is when the failed
 * charge was made, else when the PaymentIntent was created. `raw` is the PaymentIntent.
 *
 * @link https://docs.stripe.com/api/payment_intents/object#payment_intent_object-last_payment_error
 */
final readonly class PaymentFailure implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?CarbonImmutable $at,
        public ?string $code,
        public ?string $declineCode,
        public ?string $message,
        public ?string $paymentIntent,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  a PaymentIntent, its `latest_charge` expanded or not
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $at = $dataset->value('latest_charge.created') ?? $dataset->value('created');

        return new self(
            at: is_int($at) ? CarbonImmutable::createFromTimestamp($at, 'UTC') : null,
            code: self::string($dataset->value('last_payment_error.code')),
            declineCode: self::string($dataset->value('last_payment_error.decline_code')),
            message: self::string($dataset->value('last_payment_error.message')),
            paymentIntent: self::string($dataset->value('id')),
            raw: $raw,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
