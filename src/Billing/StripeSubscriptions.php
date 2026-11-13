<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\CancelStripeSubscriptionAction;
use RoundlyConsulting\Purchases\Actions\Stripe\ChangeStripeSubscriptionAction;
use RoundlyConsulting\Purchases\Actions\Stripe\CreateStripeCheckoutSessionAction;
use RoundlyConsulting\Purchases\Actions\Stripe\CreateStripeSubscriptionAction;
use RoundlyConsulting\Purchases\Actions\Stripe\PreviewStripeInvoiceAction;
use RoundlyConsulting\Purchases\Actions\Stripe\RefreshStripeSubscriptionAction;
use RoundlyConsulting\Purchases\Actions\Stripe\ResumeStripeSubscriptionAction;
use RoundlyConsulting\Purchases\Actions\Stripe\ScheduleStripeCancellationAction;
use RoundlyConsulting\Purchases\Actions\Stripe\StartStripeSubscriptionAction;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CheckoutSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\ItemChange;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\StartedSubscription;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionChange;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\CancelAt;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\ProrationBehavior;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\InvoicePreview;

/**
 * Stripe subscriptions — `Purchases::stripe()->subscriptions()`.
 *
 * A subscription is a stored Stripe Subscription or its id (`sub_…`); an item a stored
 * SubscriptionItem or its id (`si_…`). Every write records Stripe's answer locally at once —
 * the webhooks that follow find the row up to date — and returns the stored Subscription.
 */
final readonly class StripeSubscriptions
{
    /**
     * @param  (Closure(string, list<mixed>): void)|null  $recorder
     */
    public function __construct(
        private Container $container,
        private ?Closure $recorder = null,
    ) {}

    /**
     * Start a subscription for the owner's Stripe customer.
     */
    public function create(Model $owner, CreateSubscriptionData $data, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.create', [$owner, $data, $idempotencyKey]);

        return $this->container->make(CreateStripeSubscriptionAction::class)->execute($owner, $data, $idempotencyKey);
    }

    /**
     * Start a subscription like create(), and learn how the customer pays a first invoice
     * Stripe still waits for (status `incomplete`): its hosted page and the payment's client
     * secret. Needs API version 2025-03-31.basil or later.
     */
    public function start(Model $owner, CreateSubscriptionData $data, ?string $idempotencyKey = null): StartedSubscription
    {
        $this->record('subscriptions.start', [$owner, $data, $idempotencyKey]);

        return $this->container->make(StartStripeSubscriptionAction::class)->execute($owner, $data, $idempotencyKey);
    }

    /**
     * A hosted Checkout page that starts the subscription once the customer pays.
     */
    public function checkout(Model $owner, CheckoutSubscriptionData $data, ?string $idempotencyKey = null): CheckoutSession
    {
        $this->record('subscriptions.checkout', [$owner, $data, $idempotencyKey]);

        return $this->container->make(CreateStripeCheckoutSessionAction::class)->execute($owner, $data, $idempotencyKey);
    }

    /**
     * Set an item's quantity — e.g. the seat count to the member count.
     */
    public function updateQuantity(SubscriptionItem|string $item, int $quantity, ProrationBehavior $proration = ProrationBehavior::CreateProrations, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.updateQuantity', [$item, $quantity, $proration, $idempotencyKey]);

        return $this->apply(null, new SubscriptionChange([ItemChange::quantity($item, $quantity)], $proration), $idempotencyKey);
    }

    /**
     * Bill an item at another price — monthly to annual. Stripe resets the quantity to 1
     * unless one is given.
     */
    public function swap(SubscriptionItem|string $item, string $price, ?int $quantity = null, ProrationBehavior $proration = ProrationBehavior::CreateProrations, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.swap', [$item, $price, $quantity, $proration, $idempotencyKey]);

        return $this->apply(null, new SubscriptionChange([ItemChange::swap($item, $price, $quantity)], $proration), $idempotencyKey);
    }

    /**
     * Add a price as a new item — a metered price (no quantity) to bill usage.
     */
    public function addItem(Subscription|string $subscription, string $price, ?int $quantity = null, ProrationBehavior $proration = ProrationBehavior::CreateProrations, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.addItem', [$subscription, $price, $quantity, $proration, $idempotencyKey]);

        return $this->apply($subscription, new SubscriptionChange([ItemChange::add($price, $quantity)], $proration), $idempotencyKey);
    }

    /**
     * Remove an item. `clearUsage` drops a metered item's usage this period instead of billing it.
     */
    public function removeItem(SubscriptionItem|string $item, ProrationBehavior $proration = ProrationBehavior::CreateProrations, bool $clearUsage = false, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.removeItem', [$item, $proration, $clearUsage, $idempotencyKey]);

        return $this->apply(null, new SubscriptionChange([ItemChange::remove($item, $clearUsage)], $proration), $idempotencyKey);
    }

    /**
     * Apply several item changes in one call — the same change `preview()` showed.
     */
    public function change(Subscription|string|null $subscription, SubscriptionChange $change, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.change', [$subscription, $change, $idempotencyKey]);

        return $this->apply($subscription, $change, $idempotencyKey);
    }

    /**
     * Cancel now. `invoiceNow` bills pending usage and prorations; `prorate` credits the unused time.
     */
    public function cancel(Subscription|string $subscription, bool $invoiceNow = false, bool $prorate = false, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.cancel', [$subscription, $invoiceNow, $prorate, $idempotencyKey]);

        return $this->container->make(CancelStripeSubscriptionAction::class)->execute($subscription, false, $invoiceNow, $prorate, $idempotencyKey);
    }

    /**
     * Cancel at the end of the paid period; it stays active until then.
     */
    public function cancelAtPeriodEnd(Subscription|string $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.cancelAtPeriodEnd', [$subscription, $idempotencyKey]);

        return $this->container->make(CancelStripeSubscriptionAction::class)->execute($subscription, true, idempotencyKey: $idempotencyKey);
    }

    /**
     * Cancel at a chosen moment: a date, or a period helper — CancelAt::MaxPeriodEnd ends a
     * mixed-interval subscription once every item has run out its paid period. It stays active
     * until then; the date is stored in `cancels_at`. `proration` (sent only when given) says
     * how a date before the period end is prorated.
     */
    public function cancelAt(Subscription|string $subscription, CarbonInterface|CancelAt $at, ?ProrationBehavior $proration = null, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.cancelAt', [$subscription, $at, $proration, $idempotencyKey]);

        return $this->container->make(ScheduleStripeCancellationAction::class)->execute($subscription, $at, $proration, $idempotencyKey);
    }

    /**
     * Take back a scheduled cancellation — at the period end or at a chosen date. Reads the
     * subscription from Stripe first to send the one parameter that undoes it.
     */
    public function resume(Subscription|string $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->record('subscriptions.resume', [$subscription, $idempotencyKey]);

        return $this->container->make(ResumeStripeSubscriptionAction::class)->execute($subscription, $idempotencyKey);
    }

    /**
     * Read the subscription from Stripe again and record it — a reconcile. A webhook Stripe
     * stamped later than the answer keeps its state.
     */
    public function refresh(Subscription|string $subscription): Subscription
    {
        $this->record('subscriptions.refresh', [$subscription]);

        return $this->container->make(RefreshStripeSubscriptionAction::class)->execute($subscription);
    }

    /**
     * The next invoice — with a planned change applied — before anything changes.
     */
    public function preview(Subscription|string $subscription, ?SubscriptionChange $change = null): InvoicePreview
    {
        return $this->container->make(PreviewStripeInvoiceAction::class)->execute($subscription, $change);
    }

    private function apply(Subscription|string|null $subscription, SubscriptionChange $change, ?string $idempotencyKey): Subscription
    {
        return $this->container->make(ChangeStripeSubscriptionAction::class)->execute($subscription, $change, $idempotencyKey);
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function record(string $operation, array $arguments): void
    {
        if ($this->recorder !== null) {
            ($this->recorder)($operation, $arguments);
        }
    }
}
