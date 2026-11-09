<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe;

use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Signal;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SubscriptionStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\StripeMoney;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\DataSet;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

/**
 * Maps a Stripe Subscription object — from a `customer.subscription.*` webhook, a fetch, or
 * the answer to a billing call — into a subscription result: its status, period, trial,
 * customer, `cancel_at_period_end`, and one item per subscription item with its price,
 * quantity and price id.
 *
 * `past_due` is a grace period (access kept) unless `purchases.settings.stripe.past_due` is
 * `on_hold`; `unpaid` and `paused` are always on hold.
 *
 * @internal
 */
final class SubscriptionResults
{
    /**
     * @param  array<string, mixed>  $object
     * @param  string|null  $name  a plan name to give the subscription (null leaves it alone)
     * @param  string|null  $eventId  the event it came from (`evt_…`), when it came from one
     */
    public static function fromObject(array $object, ?CarbonInterface $occurredAt, string $provider = 'stripe', ?string $name = null, ?string $fallbackId = null, ?string $eventId = null, ?Signal $signal = null): GenericResult
    {
        $subscription = Subscription::fromRaw($object);
        $dataset = new DataSet($object);
        $cancelAtPeriodEnd = $dataset->value('cancel_at_period_end');

        return new GenericResult(
            provider: $provider,
            type: ResultType::Subscription,
            providerId: $subscription->id ?? (string) $fallbackId,
            status: self::status($subscription->status),
            transactionId: $subscription->id,
            // Stripe names no plan: the product only names a subscription when it is first
            // recorded, so a name the host gives it later is never overwritten.
            name: $name,
            productId: $subscription->productId,
            price: null,
            activeFrom: $subscription->currentPeriodStart,
            trialEndsAt: $subscription->trialEnd,
            endsAt: $subscription->currentPeriodEnd,
            items: self::items($dataset),
            raw: $object,
            occurredAt: $occurredAt,
            customerId: self::id($dataset->value('customer')),
            cancelAtPeriodEnd: is_bool($cancelAtPeriodEnd) ? $cancelAtPeriodEnd : null,
            eventId: $eventId,
            signal: $signal,
        );
    }

    private static function status(?SubscriptionStatus $status): Status
    {
        if ($status === SubscriptionStatus::PastDue) {
            $key = 'purchases.settings.stripe.past_due';

            return PurchasesConfig::oneOf(config($key), $key, [Status::InGracePeriod->value, Status::OnHold->value], Status::InGracePeriod->value) === Status::OnHold->value
                ? Status::OnHold
                : Status::InGracePeriod;
        }

        return $status?->status() ?? Status::Processing;
    }

    /**
     * @return list<ResultItem>
     */
    private static function items(DataSet $dataset): array
    {
        $items = [];
        $data = $dataset->value('items.data');

        foreach (is_array($data) ? $data : [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            /** @var array<string, mixed> $raw */
            $item = new DataSet($raw);
            $id = self::id($item->value('id'));
            $priceId = self::id($item->value('price.id'));
            $quantity = $item->value('quantity');

            $items[] = new ResultItem(
                name: self::name($item) ?? $priceId ?? $id ?? 'item',
                providerId: $id,
                price: StripeMoney::fromDataSet($item, 'price.unit_amount', 'price.currency'),
                quantity: is_int($quantity) ? $quantity : 1,
                metered: $item->value('price.recurring.usage_type') === 'metered',
                priceId: $priceId,
            );
        }

        return $items;
    }

    /**
     * The price's nickname, else its lookup key, else its product.
     */
    private static function name(DataSet $item): ?string
    {
        foreach (['price.nickname', 'price.lookup_key', 'price.product'] as $key) {
            $value = self::id($item->value($key));

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * An id, or the id of an expanded object.
     */
    private static function id(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
