<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;

/**
 * The payment method a setup Checkout collected (`Purchases::stripe()->checkout()->setup()`) is
 * now the Stripe customer's default for invoices (`invoice_settings.default_payment_method`), so
 * every subscription without a default of its own charges it.
 *
 * `$customerId` is the Stripe customer, `$owner` the model linked to it (null when none is), and
 * `$paymentMethodId` the method (`pm_…`). `$result->raw()` is the Checkout Session. Fires for
 * each delivery of the webhook: turn on `purchases.audit.deduplicate` to skip redeliveries.
 */
final class DefaultPaymentMethodUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly ProviderResult $result,
        public readonly string $customerId,
        public readonly string $paymentMethodId,
        public readonly ?Model $owner = null,
    ) {}
}
