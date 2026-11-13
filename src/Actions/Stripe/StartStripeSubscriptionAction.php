<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\StartedSubscription;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingDefaults;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingResponse;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;
use RoundlyConsulting\Purchases\Support\SubscriptionLimit;

/**
 * Starts a Stripe subscription as CreateStripeSubscriptionAction does — recorded for the owner,
 * lifecycle events fired, `purchases.subscriptions.one_per_owner` honoured — and also answers how
 * the customer pays a first invoice Stripe still waits for: the subscription is created with its
 * `latest_invoice.confirmation_secret` expanded, and an `incomplete` one hands back the invoice's
 * `hosted_invoice_url` and the payment's client secret.
 *
 * The expanded invoice is not stored with the subscription: its id is, as on every other answer.
 * `confirmation_secret` needs API version 2025-03-31.basil or later.
 */
final readonly class StartStripeSubscriptionAction
{
    /** The first API version whose invoices carry `confirmation_secret`. */
    public const string MINIMUM_API_VERSION = '2025-03-31.basil';

    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
        private RecordStripeSubscriptionAction $record,
        private SubscriptionLimit $limit,
    ) {}

    public function execute(Model $owner, CreateSubscriptionData $data, ?string $idempotencyKey = null): StartedSubscription
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);
        BillingDefaults::assertApiVersion(self::MINIMUM_API_VERSION, 'Starting a subscription with its payment details');
        $mode = $data->billingMode ?? BillingDefaults::billingMode();
        BillingDefaults::assertSupports($mode);
        $params = $data->toParams($customer, $mode, BillingDefaults::trialEndBehavior());
        $params['expand'] = ['latest_invoice.confirmation_secret'];
        $key = IdempotencyKey::resolve($idempotencyKey);

        return $this->limit->guard($owner, function () use ($params, $key, $owner, $data): StartedSubscription {
            $response = $this->api->post('/subscriptions', $params, $key);
            $invoice = $response->body['latest_invoice'] ?? null;
            $body = $response->body;

            if (is_array($invoice)) {
                // Stored as Stripe sends it unexpanded: the id. The client secret stays out of the database.
                $body['latest_invoice'] = is_string($invoice['id'] ?? null) ? $invoice['id'] : null;
            }

            $subscription = $this->record->execute(new BillingResponse($body, $response->date), $owner, $data->name);

            if (($response->body['status'] ?? null) !== 'incomplete' || ! is_array($invoice)) {
                return new StartedSubscription($subscription);
            }

            return new StartedSubscription(
                subscription: $subscription,
                invoiceId: self::filled($invoice['id'] ?? null),
                hostedInvoiceUrl: self::filled($invoice['hosted_invoice_url'] ?? null),
                clientSecret: self::filled(data_get($invoice, 'confirmation_secret.client_secret')),
            );
        });
    }

    private static function filled(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
