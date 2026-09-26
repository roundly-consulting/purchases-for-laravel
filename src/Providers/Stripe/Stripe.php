<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe;

use Illuminate\Http\Request;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Contracts\VerifiesConnectivity;
use RoundlyConsulting\Purchases\DataTransferObjects\ConnectivityResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\EventType;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentIntentStatus;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Invoice;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentIntent;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\StripeEvent;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\StripeMoney;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\DataSet;
use Throwable;

class Stripe extends BaseProvider implements VerifiesConnectivity
{
    /** @var array<string, mixed> */
    protected readonly array $config;

    public function __construct(
        private readonly WebhookSignature $signatures = new WebhookSignature,
        private ?StripeClient $client = null,
    ) {
        /** @var array<string, mixed> $config */
        $config = config('purchases.settings.stripe');
        $this->config = $config;
    }

    /**
     * Verify the webhook signature against the raw body and decode the event.
     */
    public function notification(Request $request): StripeEvent
    {
        return $this->event($request);
    }

    public function event(Request $request): StripeEvent
    {
        $payload = $request->getContent();
        $header = (string) $request->header('Stripe-Signature');

        $this->signatures->verify(
            payload: $payload,
            header: $header,
            secret: $this->webhookSecret(),
            tolerance: (int) ($this->config['tolerance'] ?? 300),
        );

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw VerificationException::because('Malformed Stripe webhook payload.');
        }

        /** @var array<string, mixed> $decoded */
        return StripeEvent::fromRaw($decoded);
    }

    public function paymentIntent(string $id): PaymentIntent
    {
        return PaymentIntent::fromRaw($this->client()->request()->get("/payment_intents/{$id}")->json());
    }

    public function subscription(string $id): Subscription
    {
        return Subscription::fromRaw($this->client()->request()->get("/subscriptions/{$id}")->json());
    }

    public function session(string $id): CheckoutSession
    {
        return CheckoutSession::fromRaw($this->client()->request()->get("/checkout/sessions/{$id}")->json());
    }

    public function invoice(string $id): Invoice
    {
        return Invoice::fromRaw($this->client()->request()->get("/invoices/{$id}")->json());
    }

    /**
     * Retrieve and verify a payment intent or session by id.
     */
    public function callback(Request $request): PaymentIntent|CheckoutSession
    {
        $sessionId = $request->input('session_id');

        if (is_string($sessionId) && $sessionId !== '') {
            return $this->session($sessionId);
        }

        $paymentIntentId = $request->input('payment_intent');

        if (is_string($paymentIntentId) && $paymentIntentId !== '') {
            return $this->paymentIntent($paymentIntentId);
        }

        throw VerificationException::because('No Stripe session or payment intent id provided.');
    }

    public function result(Request $request): ProviderResult
    {
        $event = $this->event($request);
        $object = new DataSet($event->object);

        // An event this package does not map is audited, never recorded as a purchase.
        if ($event->type->resultType() === ResultType::Unknown) {
            return new GenericResult(
                provider: $this->id(),
                type: ResultType::Unknown,
                providerId: (string) $event->id,
                status: Status::Processing,
                raw: $event->object,
            );
        }

        if ($event->type->resultType() === ResultType::Refund) {
            return $this->refundResult($event, $object);
        }

        if ($event->type->resultType() === ResultType::Subscription) {
            $subscription = Subscription::fromRaw($event->object);

            return new GenericResult(
                provider: $this->id(),
                type: ResultType::Subscription,
                providerId: $subscription->id ?? (string) $event->id,
                status: $subscription->status?->status() ?? Status::Processing,
                transactionId: $subscription->id,
                name: null,
                productId: null,
                price: null,
                activeFrom: $subscription->currentPeriodStart,
                trialEndsAt: $subscription->trialEnd,
                endsAt: $subscription->currentPeriodEnd,
                items: [],
                raw: $event->object,
            );
        }

        $id = $object->value('id');
        $id = is_string($id) && $id !== '' ? $id : (string) $event->id;

        // Subscription billing (a subscription/setup Checkout, a subscription invoice and
        // the payment behind it) belongs to the subscription, whose customer.subscription.*
        // events carry its state — recording it as a one-off Purchase would fire
        // PurchaseCompleted for every renewal.
        if ($this->isSubscriptionBilling($event->type, $object)) {
            return new GenericResult(
                provider: $this->id(),
                type: ResultType::Notification,
                providerId: $id,
                status: Status::Processing,
                raw: $event->object,
            );
        }

        // A payment Checkout is keyed on its PaymentIntent, so the session and the
        // payment_intent.* events describe one purchase — and a refund or dispute of that
        // payment links to it.
        $paymentIntent = $event->type === EventType::CheckoutSessionCompleted ? $object->value('payment_intent') : null;
        $key = is_string($paymentIntent) && $paymentIntent !== '' ? $paymentIntent : $id;

        return new GenericResult(
            provider: $this->id(),
            type: ResultType::Purchase,
            providerId: $key,
            status: $this->purchaseStatus($event->type, $object),
            transactionId: $key,
            name: null,
            productId: null,
            price: StripeMoney::fromDataSet($object, $this->purchaseAmountKey($event->type), 'currency'),
            activeFrom: null,
            trialEndsAt: null,
            endsAt: null,
            items: [],
            raw: $event->object,
        );
    }

    /**
     * Confirm the secret key works by fetching the account balance (a cheap,
     * always-available authed endpoint).
     */
    public function verifyConnectivity(): ConnectivityResult
    {
        try {
            $this->client()->request()->get('/balance');
        } catch (Throwable $e) {
            return ConnectivityResult::failed($e->getMessage());
        }

        return ConnectivityResult::ok('Stripe secret key is valid.');
    }

    /**
     * Whether a purchase-shaped event is really subscription billing: a Checkout in
     * `subscription` or `setup` mode, an invoice generated by a subscription (the
     * `parent.subscription_details.subscription` of API versions since 2025-03-31, the
     * `subscription` field before, or a `subscription_*` billing reason), or a
     * PaymentIntent paying an invoice (the pre-2025-03-31 `invoice` field).
     */
    private function isSubscriptionBilling(EventType $type, DataSet $object): bool
    {
        return match ($type) {
            EventType::CheckoutSessionCompleted => in_array($object->value('mode'), ['subscription', 'setup'], true),
            EventType::InvoicePaid, EventType::InvoicePaymentFailed => self::filled($object->value('parent.subscription_details.subscription'))
                || self::filled($object->value('subscription'))
                || str_starts_with(is_string($reason = $object->value('billing_reason')) ? $reason : '', 'subscription'),
            EventType::PaymentIntentSucceeded, EventType::PaymentIntentFailed => self::filled($object->value('invoice')),
            default => false,
        };
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * Each purchase event carries its amount under the key of its own object: a
     * PaymentIntent `amount`, a Checkout Session `amount_total`, an Invoice `amount_paid`
     * (or `amount_due` when the payment failed, where nothing was paid).
     */
    private function purchaseAmountKey(EventType $type): string
    {
        return match ($type) {
            EventType::CheckoutSessionCompleted => 'amount_total',
            EventType::InvoicePaid => 'amount_paid',
            EventType::InvoicePaymentFailed => 'amount_due',
            default => 'amount',
        };
    }

    /**
     * A PaymentIntent reports its own `status`; a completed Checkout Session is settled
     * once its `payment_status` is `paid` (or nothing was due) and still pending for a
     * delayed payment method; the invoice events say the outcome in their name.
     */
    private function purchaseStatus(EventType $type, DataSet $object): Status
    {
        if ($type === EventType::PaymentIntentFailed || $type === EventType::InvoicePaymentFailed) {
            return Status::Failed;
        }

        if ($type === EventType::InvoicePaid) {
            return Status::Completed;
        }

        if ($type === EventType::CheckoutSessionCompleted) {
            return in_array($object->value('payment_status'), ['paid', 'no_payment_required'], true)
                ? Status::Completed
                : Status::Pending;
        }

        $status = $object->value('status');

        return is_string($status)
            ? (PaymentIntentStatus::tryFrom($status)?->status() ?? Status::Processing)
            : Status::Processing;
    }

    private function refundResult(StripeEvent $event, DataSet $object): GenericResult
    {
        $chargeback = $event->type->isChargeback();

        // Disputes key on payment_intent; charge refunds expose it directly too.
        $paymentIntent = $object->value('payment_intent');
        $id = $object->value('id');

        $providerId = is_string($paymentIntent) && $paymentIntent !== ''
            ? $paymentIntent
            : (is_string($id) ? $id : (string) $event->id);

        $amountKey = $chargeback ? 'amount' : 'amount_refunded';
        $reason = $object->value('reason');

        return new GenericResult(
            provider: $this->id(),
            type: ResultType::Refund,
            providerId: $providerId,
            // `charge.refunded` fires for partial refunds too; only a charge Stripe marks
            // `refunded` is fully refunded, so a partial refund leaves the purchase completed.
            status: ! $chargeback && $object->value('refunded') === false ? Status::Completed : Status::Refunded,
            transactionId: is_string($paymentIntent) && $paymentIntent !== '' ? $paymentIntent : null,
            price: StripeMoney::fromDataSet($object, $amountKey, 'currency'),
            raw: $event->object,
            refundReason: is_string($reason) ? $reason : null,
            chargeback: $chargeback,
        );
    }

    public function id(): string
    {
        return 'stripe';
    }

    private function webhookSecret(): string
    {
        $secret = $this->config['webhook_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw VerificationException::because('Stripe webhook secret is not configured.');
        }

        return $secret;
    }

    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $secret = $this->config['secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw VerificationException::because('Stripe secret key is not configured.');
        }

        $baseUrl = $this->config['base_url'] ?? 'https://api.stripe.com/v1';
        $apiVersion = $this->config['api_version'] ?? '';

        return $this->client = new StripeClient(
            secret: $secret,
            baseUrl: is_string($baseUrl) ? $baseUrl : 'https://api.stripe.com/v1',
            apiVersion: is_string($apiVersion) ? $apiVersion : '',
        );
    }
}
