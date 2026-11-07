<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Billing;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\SubscriptionItemModel;

/**
 * Finds the Stripe subscription a billing call is about: a stored Stripe subscription, its
 * Stripe id, or the subscription a subscription item belongs to. A stored subscription of
 * another provider (Apple, Google) is refused, never sent to Stripe.
 *
 * @internal
 */
final readonly class SubscriptionLocator
{
    public function __construct(
        private BillingApi $api,
    ) {}

    /**
     * @throws BillingException
     */
    public static function id(Subscription|string $subscription): string
    {
        if (is_string($subscription)) {
            return self::filled($subscription, 'subscription');
        }

        if ($subscription->provider !== 'stripe') {
            throw BillingException::notStripe('subscription');
        }

        return self::filled((string) $subscription->provider_id, 'subscription');
    }

    /**
     * The Stripe subscription an item (`si_…`) belongs to: read from the stored item, or
     * asked of Stripe when it is not stored.
     */
    public function forItem(string $item): string
    {
        $stored = SubscriptionItemModel::query()
            ->where('provider_id', $item)
            ->with('subscription')
            ->get()
            ->first(static fn ($stored): bool => $stored->subscription?->provider === 'stripe');

        if ($stored?->subscription !== null) {
            return self::id($stored->subscription);
        }

        $subscription = $this->api->get(BillingApi::path('subscription_items', $item))->body['subscription'] ?? null;
        $subscription = is_array($subscription) ? ($subscription['id'] ?? null) : $subscription;

        return self::filled(is_string($subscription) ? $subscription : '', 'subscription item');
    }

    private static function filled(string $id, string $what): string
    {
        if (trim($id) === '') {
            throw BillingException::invalid("The {$what} has no Stripe id.");
        }

        return $id;
    }
}
