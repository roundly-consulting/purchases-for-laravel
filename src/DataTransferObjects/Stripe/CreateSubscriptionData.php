<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\BillingMode;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\CollectionMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MissingPaymentMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentBehavior;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SaveDefaultPaymentMethod;

/**
 * A subscription to start for an owner's Stripe customer.
 *
 * Null options are not sent, so Stripe's defaults apply: no trial, automatic tax as the
 * account sets it, `charge_automatically`, `allow_incomplete`. `name` names the local
 * subscription row (what `subscribedTo()` checks); Stripe itself names no plan.
 *
 * `billingMode` Flexible lets prices of different intervals share the subscription — an
 * annual licensed price next to a monthly metered one; each interval must be a multiple of the
 * shortest (Stripe checks). A trial needs no payment method; `trialEndBehavior` says what
 * happens when it ends without one. Unset, they fall back to the `billing_mode` and
 * `missing_payment_method` settings under `purchases.settings.stripe`, then to Stripe's
 * defaults (flexible on the pinned API version; `create_invoice`).
 *
 * `saveDefaultPaymentMethod: OnSubscription` keeps the method the first invoice is paid with as
 * the subscription's default. With `paymentBehavior: DefaultIncomplete` the subscription starts
 * `incomplete` and waits for that payment: start it with `subscriptions()->start()` to get the
 * first invoice's hosted page and the payment's client secret.
 */
final readonly class CreateSubscriptionData
{
    /**
     * @param  list<SubscriptionPrice>  $prices
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public array $prices,
        public ?string $name = null,
        public ?int $trialDays = null,
        public ?bool $automaticTax = null,
        public ?CollectionMethod $collectionMethod = null,
        public ?int $daysUntilDue = null,
        public ?PaymentBehavior $paymentBehavior = null,
        public array $metadata = [],
        public ?BillingMode $billingMode = null,
        public ?MissingPaymentMethod $trialEndBehavior = null,
        public ?SaveDefaultPaymentMethod $saveDefaultPaymentMethod = null,
    ) {
        if ($prices === []) {
            throw BillingException::invalid('A subscription needs at least one price.');
        }

        if ($trialDays !== null && $trialDays < 1) {
            throw BillingException::invalid('A trial must last at least one day.');
        }

        if ($trialEndBehavior !== null && $trialDays === null) {
            throw BillingException::invalid('A trial end behaviour needs a trial.');
        }

        if ($daysUntilDue !== null && $collectionMethod !== CollectionMethod::SendInvoice) {
            throw BillingException::invalid('Days until due apply only to the send_invoice collection method.');
        }
    }

    /**
     * The defaults apply only where the data leaves a value unset — a trial end behaviour only
     * to a subscription with a trial.
     *
     * @return array<string, mixed>
     */
    public function toParams(string $customer, ?BillingMode $defaultBillingMode = null, ?MissingPaymentMethod $defaultTrialEndBehavior = null): array
    {
        $billingMode = $this->billingMode ?? $defaultBillingMode;
        $trialEnd = $this->trialDays === null ? null : $this->trialEndBehavior ?? $defaultTrialEndBehavior;

        return [
            'customer' => $customer,
            'items' => array_map(static fn (SubscriptionPrice $price): array => $price->toParams(), $this->prices),
            'trial_period_days' => $this->trialDays,
            'automatic_tax' => $this->automaticTax !== null ? ['enabled' => $this->automaticTax] : null,
            'collection_method' => $this->collectionMethod?->value,
            'days_until_due' => $this->daysUntilDue,
            'payment_behavior' => $this->paymentBehavior?->value,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'billing_mode' => $billingMode !== null ? ['type' => $billingMode->value] : null,
            'trial_settings' => $trialEnd !== null ? ['end_behavior' => ['missing_payment_method' => $trialEnd->value]] : null,
            'payment_settings' => $this->saveDefaultPaymentMethod !== null ? ['save_default_payment_method' => $this->saveDefaultPaymentMethod->value] : null,
        ];
    }
}
