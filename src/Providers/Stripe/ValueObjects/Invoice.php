<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A Stripe invoice — fetched by id (`Stripe::invoice()`) or listed for an owner
 * (`Purchases::stripe()->invoices()->list()`).
 *
 * `total` is the invoice total after discounts and tax, `amountDue` what the customer pays
 * (after credit and the customer balance), `amountPaid` what was paid; `currency` is upper
 * case. `hostedInvoiceUrl` (view and pay it) and `invoicePdf` stay null until Stripe finalizes
 * the invoice, as `number` does.
 */
final class Invoice implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $status,
        public readonly ?Money $amountPaid,
        public readonly ?string $customer,
        public readonly ?string $subscription,
        public readonly array $raw,
        public readonly ?string $number = null,
        public readonly ?CarbonImmutable $created = null,
        public readonly ?Money $total = null,
        public readonly ?Money $amountDue = null,
        public readonly ?string $currency = null,
        public readonly ?string $hostedInvoiceUrl = null,
        public readonly ?string $invoicePdf = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $created = $dataset->value('created');
        $currency = $dataset->value('currency');

        return new self(
            id: self::string($dataset->value('id')),
            status: self::string($dataset->value('status')),
            amountPaid: StripeMoney::fromDataSet($dataset, 'amount_paid', 'currency'),
            customer: self::id($dataset->value('customer')),
            // `parent.subscription_details.subscription` since API version 2025-03-31.
            subscription: self::id($dataset->value('parent.subscription_details.subscription') ?? $dataset->value('subscription')),
            raw: $raw,
            number: self::string($dataset->value('number')),
            created: is_int($created) ? CarbonImmutable::createFromTimestamp($created, 'UTC') : null,
            total: StripeMoney::fromDataSet($dataset, 'total', 'currency'),
            amountDue: StripeMoney::fromDataSet($dataset, 'amount_due', 'currency'),
            currency: is_string($currency) && $currency !== '' ? strtoupper($currency) : null,
            hostedInvoiceUrl: self::string($dataset->value('hosted_invoice_url')),
            invoicePdf: self::string($dataset->value('invoice_pdf')),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * An id, or the id of an expanded object.
     */
    private static function id(mixed $value): ?string
    {
        return self::string(is_array($value) ? ($value['id'] ?? null) : $value);
    }
}
