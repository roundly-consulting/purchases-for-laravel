<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Closure;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\OwnerPurchases;

/**
 * `purchases.subscriptions.one_per_owner`: a new Stripe subscription (or a Checkout that would
 * start one) is refused while the owner still holds one that is not over.
 *
 * The check and the start run under one atomic cache lock per owner, held until the new
 * subscription is recorded — so of two starts racing for one owner, the second waits, then sees
 * the first and is refused. Atomic only across servers that share the lock store
 * (`purchases.subscriptions.lock.store`, else the default cache store): redis, memcached,
 * database or dynamodb.
 *
 * @internal building block of the subscription billing actions.
 */
final readonly class SubscriptionLimit
{
    /** Long enough for a Stripe call and the recording after it; released as soon as both are done. */
    private const int LOCK_SECONDS = 120;

    public function __construct(
        private Factory $cache,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $start
     * @return TResult
     *
     * @throws BillingException the owner already holds a subscription, or the lock stayed taken
     */
    public function guard(Model $owner, Closure $start): mixed
    {
        if (! Config::boolean('purchases.subscriptions.one_per_owner')) {
            return $start();
        }

        $store = $this->cache->store(PurchasesConfig::optionalString(config('purchases.subscriptions.lock.store'), 'purchases.subscriptions.lock.store'))->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidConfigurationException('[purchases.subscriptions.lock.store] must be a cache store with atomic locks, '.get_debug_type($store).' given.');
        }

        $wait = PurchasesConfig::integer(config('purchases.subscriptions.lock.wait'), 'purchases.subscriptions.lock.wait', 10, min: 0);
        $key = is_scalar($id = $owner->getKey()) ? (string) $id : '';
        $lock = $store->lock('purchases:subscription:'.$owner->getMorphClass().':'.$key, self::LOCK_SECONDS);

        try {
            /** @var TResult */
            return $lock->block($wait, static function () use ($owner, $start): mixed {
                if ((new OwnerPurchases($owner))->hasOngoingSubscription()) {
                    throw BillingException::alreadySubscribed($owner);
                }

                return $start();
            });
        } catch (LockTimeoutException) {
            throw BillingException::subscriptionLocked($owner);
        }
    }
}
