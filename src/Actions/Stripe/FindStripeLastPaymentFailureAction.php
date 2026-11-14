<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentFailure;

/**
 * Reads an owner's last failed payment that is still unpaid: the newest of the customer's
 * recent PaymentIntents (`GET /v1/payment_intents?customer=…`, newest first, the failed charge
 * expanded) that carries a `last_payment_error` and neither succeeded nor was canceled. Stripe
 * clears that error once a later attempt goes through, so a failure that was settled since is
 * not reported.
 *
 * Null when the owner has no Stripe customer (Stripe is not asked) or none of the last
 * {@see self::LOOKBACK} PaymentIntents failed. Nothing is stored and no event fires; a Stripe
 * failure throws a typed StripeException, a missing secret key InvalidConfigurationException.
 */
final readonly class FindStripeLastPaymentFailureAction
{
    public const int LOOKBACK = 10;

    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    public function execute(Model $owner): ?PaymentFailure
    {
        $customer = $this->customers->customerId($owner, 'stripe');

        if ($customer === null) {
            return null;
        }

        $intents = $this->api->get('/payment_intents', [
            'customer' => $customer,
            'limit' => self::LOOKBACK,
            'expand' => ['data.latest_charge'],
        ])->body['data'] ?? [];

        foreach (is_array($intents) ? $intents : [] as $intent) {
            if (! is_array($intent) || ! is_array($intent['last_payment_error'] ?? null)) {
                continue;
            }

            if (in_array($intent['status'] ?? null, ['succeeded', 'canceled'], true)) {
                continue;
            }

            /** @var array<string, mixed> $intent */
            return PaymentFailure::fromRaw($intent);
        }

        return null;
    }
}
