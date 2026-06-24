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
        $statusValue = $object->value('status');
        $status = is_string($statusValue)
            ? (PaymentIntentStatus::tryFrom($statusValue)?->status() ?? Status::Processing)
            : Status::Processing;

        return new GenericResult(
            provider: $this->id(),
            type: ResultType::Purchase,
            providerId: is_string($id) ? $id : (string) $event->id,
            status: $event->type === EventType::PaymentIntentFailed ? Status::Failed : $status,
            transactionId: is_string($id) ? $id : null,
            name: null,
            productId: null,
            price: StripeMoney::fromDataSet($object, 'amount', 'currency'),
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
            status: Status::Refunded,
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
