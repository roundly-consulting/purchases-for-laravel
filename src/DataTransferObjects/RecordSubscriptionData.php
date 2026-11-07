<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\AppTime;

final readonly class RecordSubscriptionData
{
    /**
     * `name` is the plan name the provider gives the subscription, or null when it gives
     * none (Stripe): a named result renames the subscription, an unnamed one leaves the
     * stored name alone — a new subscription is then named after `productId`, or failing
     * that its provider id.
     *
     * `cancelAtPeriodEnd` is null when the provider does not say (Apple, Google), which leaves
     * the stored flag alone. `owner` links a subscription that has no owner yet to that model;
     * an owner already linked is never replaced.
     *
     * @param  list<ResultItem>  $items
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public Status $status,
        public ?string $name,
        public ?string $transactionId = null,
        public ?Money $price = null,
        public ?CarbonInterface $activeFrom = null,
        public ?CarbonInterface $trialEndsAt = null,
        public ?CarbonInterface $endsAt = null,
        public array $items = [],
        public array $meta = [],
        public ?CarbonInterface $occurredAt = null,
        public ?string $productId = null,
        public ?bool $cancelAtPeriodEnd = null,
        public ?Model $owner = null,
    ) {}

    /**
     * Provider dates arrive in UTC; they are moved into the application's timezone, the one
     * Eloquent reads them back in, so they keep their instant.
     */
    public static function fromResult(ProviderResult $result, ?Model $owner = null): self
    {
        return new self(
            provider: $result->provider(),
            providerId: $result->providerId(),
            status: $result->status(),
            name: $result->name(),
            transactionId: $result->transactionId(),
            price: $result->price(),
            activeFrom: AppTime::of($result->activeFrom()),
            trialEndsAt: AppTime::of($result->trialEndsAt()),
            endsAt: AppTime::of($result->endsAt()),
            items: $result->items(),
            meta: $result->raw(),
            occurredAt: AppTime::of($result->occurredAt()),
            productId: $result->productId(),
            cancelAtPeriodEnd: $result instanceof GenericResult ? $result->cancelAtPeriodEnd() : null,
            owner: $owner,
        );
    }
}
