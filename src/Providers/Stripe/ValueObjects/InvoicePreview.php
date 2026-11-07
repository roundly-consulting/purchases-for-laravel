<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * The invoice Stripe would issue next for a subscription — with a planned change applied, if
 * one was given — so a UI can show the price before the change is confirmed. A preview
 * cannot be paid, changed or listed, and it changes nothing.
 *
 * `amountDue` is what the customer would pay (after credit and the customer balance),
 * `total` the invoice total and `subtotal` the total before discounts and tax. `prorations()`
 * holds the lines that bill the change itself.
 *
 * @link https://docs.stripe.com/api/invoices/create_preview
 */
final readonly class InvoicePreview implements FromRaw
{
    /**
     * @param  list<InvoiceLine>  $lines
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?Money $amountDue,
        public ?Money $total,
        public ?Money $subtotal,
        public ?string $currency,
        public ?Carbon $periodStart,
        public ?Carbon $periodEnd,
        public ?Carbon $nextPaymentAttempt,
        public array $lines,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $currency = $dataset->value('currency');
        $lines = [];

        foreach ((array) $dataset->value('lines.data', []) as $line) {
            if (is_array($line)) {
                /** @var array<string, mixed> $line */
                $lines[] = InvoiceLine::fromRaw($line);
            }
        }

        return new self(
            amountDue: StripeMoney::fromDataSet($dataset, 'amount_due', 'currency'),
            total: StripeMoney::fromDataSet($dataset, 'total', 'currency'),
            subtotal: StripeMoney::fromDataSet($dataset, 'subtotal', 'currency'),
            currency: is_string($currency) ? strtoupper($currency) : null,
            periodStart: self::epoch($dataset->value('period_start')),
            periodEnd: self::epoch($dataset->value('period_end')),
            nextPaymentAttempt: self::epoch($dataset->value('next_payment_attempt')),
            lines: $lines,
            raw: $raw,
        );
    }

    /**
     * The lines that bill the planned change itself.
     *
     * @return list<InvoiceLine>
     */
    public function prorations(): array
    {
        return array_values(array_filter($this->lines, static fn (InvoiceLine $line): bool => $line->proration));
    }

    private static function epoch(mixed $value): ?Carbon
    {
        return is_int($value) ? Carbon::createFromTimestamp($value) : null;
    }
}
