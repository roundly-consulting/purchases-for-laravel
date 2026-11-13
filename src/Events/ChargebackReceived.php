<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;

/**
 * A store took a payment back from you — a Stripe dispute, an Apple or Google refund the
 * customer asked the store for.
 *
 * `$owner` is whose payment it was: the owner of the billing customer the result names (a Stripe
 * dispute's customer, see `GenericResult::customerId()`), else the owner of the purchase the
 * chargeback links to, else null — e.g. a dispute of a subscription invoice when no secret key is
 * configured to look its charge up.
 */
final class ChargebackReceived
{
    use Dispatchable;

    public function __construct(
        public readonly PurchaseRefund $refund,
        public readonly ProviderResult $result,
        public readonly ?Model $owner = null,
    ) {}
}
