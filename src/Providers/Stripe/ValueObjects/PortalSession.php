<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A Stripe Customer Portal session: redirect the customer to `url` to manage payment
 * methods, invoices and (as the portal is configured) their subscription. The URL is
 * short-lived and for this customer only — create one per visit, never store it.
 *
 * @link https://docs.stripe.com/api/customer_portal/sessions/object
 */
final readonly class PortalSession implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $url,
        public ?string $customer,
        public ?string $returnUrl,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $customer = $dataset->value('customer');
        $returnUrl = $dataset->value('return_url');

        return new self(
            id: (string) $dataset->value('id'),
            url: (string) $dataset->value('url'),
            customer: is_string($customer) ? $customer : null,
            returnUrl: is_string($returnUrl) ? $returnUrl : null,
            raw: $raw,
        );
    }
}
