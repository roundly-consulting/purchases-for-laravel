<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\BillingMode;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MissingPaymentMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentMethodCollection;

/**
 * A hosted Stripe Checkout page that starts a subscription for an owner's Stripe customer —
 * for when Stripe should collect the payment details.
 *
 * Stripe appends nothing to `successUrl` on its own: put `{CHECKOUT_SESSION_ID}` in it to get
 * the session id back. With automatic tax on, Checkout saves the address it collects to the
 * customer, which Stripe Tax needs.
 *
 * A free trial without a payment method: `trialDays`, `paymentMethodCollection: IfRequired` and a
 * `trialEndBehavior` (else `purchases.settings.stripe.missing_payment_method`, else Stripe's
 * `create_invoice`). `billingMode` (else `purchases.settings.stripe.billing_mode`) is passed to
 * the subscription Checkout creates — but Checkout cannot create a mixed-interval subscription:
 * use `subscriptions()->create()` for one.
 */
final readonly class CheckoutSubscriptionData
{
    /**
     * @param  list<SubscriptionPrice>  $prices
     * @param  array<string, string>  $metadata  set on the subscription Checkout creates
     */
    public function __construct(
        public array $prices,
        public string $successUrl,
        public ?string $cancelUrl = null,
        public ?int $trialDays = null,
        public ?bool $automaticTax = null,
        public array $metadata = [],
        public ?string $clientReferenceId = null,
        public ?BillingMode $billingMode = null,
        public ?MissingPaymentMethod $trialEndBehavior = null,
        public ?PaymentMethodCollection $paymentMethodCollection = null,
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
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => array_map(static fn (SubscriptionPrice $price): array => [
                'price' => $price->price,
                'quantity' => $price->quantity,
            ], $this->prices),
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'client_reference_id' => $this->clientReferenceId,
            'automatic_tax' => $this->automaticTax !== null ? ['enabled' => $this->automaticTax] : null,
            'customer_update' => $this->automaticTax === true ? ['address' => 'auto'] : null,
            'payment_method_collection' => $this->paymentMethodCollection?->value,
            'subscription_data' => array_filter([
                'trial_period_days' => $this->trialDays,
                'metadata' => $this->metadata === [] ? null : $this->metadata,
                'billing_mode' => $billingMode !== null ? ['type' => $billingMode->value] : null,
                'trial_settings' => $trialEnd !== null ? ['end_behavior' => ['missing_payment_method' => $trialEnd->value]] : null,
            ], static fn (mixed $value): bool => $value !== null) ?: null,
        ];
    }
}
