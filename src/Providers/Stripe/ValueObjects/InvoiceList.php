<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

/**
 * One page of an owner's Stripe invoices, newest first (`Purchases::stripe()->invoices()->list()`).
 * `hasMore` says another page follows; pass `nextCursor()` as `startingAfter` to read it.
 */
final readonly class InvoiceList
{
    /**
     * @param  list<Invoice>  $invoices
     */
    public function __construct(
        public array $invoices,
        public bool $hasMore,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  a Stripe list object
     */
    public static function fromRaw(array $raw): self
    {
        $invoices = [];

        foreach (is_array($raw['data'] ?? null) ? $raw['data'] : [] as $invoice) {
            if (is_array($invoice)) {
                /** @var array<string, mixed> $invoice */
                $invoices[] = Invoice::fromRaw($invoice);
            }
        }

        return new self($invoices, ($raw['has_more'] ?? false) === true);
    }

    /**
     * The `startingAfter` that reads the next page — the last invoice's id — or null on the last page.
     */
    public function nextCursor(): ?string
    {
        if (! $this->hasMore || $this->invoices === []) {
            return null;
        }

        return $this->invoices[array_key_last($this->invoices)]->id;
    }

    public function isEmpty(): bool
    {
        return $this->invoices === [];
    }
}
