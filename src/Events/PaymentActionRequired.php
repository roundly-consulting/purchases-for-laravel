<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * A payment waits for the customer — 3-D Secure, a bank redirect (Stripe
 * `invoice.payment_action_required`). Send them to `$actionUrl`, the invoice's hosted page.
 *
 * `$subscription` is the stored subscription the invoice bills, if any; `$owner` its owner, or
 * the owner of the billing customer the invoice names. `$result->providerId()` is the invoice id
 * and `$result->raw()` the invoice. Fires for each delivery of the event: turn on
 * `purchases.audit.deduplicate` to skip redeliveries.
 */
final class PaymentActionRequired
{
    use Dispatchable;

    public function __construct(
        public readonly ProviderResult $result,
        public readonly ?Subscription $subscription = null,
        public readonly ?Model $owner = null,
        public readonly ?string $actionUrl = null,
    ) {}
}
