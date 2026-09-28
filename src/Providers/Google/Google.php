<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google;

use Illuminate\Http\Request;
use RoundlyConsulting\Crypto\Codec\Base64;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Codec\InvalidEncodingException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Contracts\VerifiesConnectivity;
use RoundlyConsulting\Purchases\DataTransferObjects\ConnectivityResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Providers\Google\Auth\PushAuthenticator;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;
use RoundlyConsulting\Purchases\Providers\Google\Enums\NotificationType;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\DeveloperNotification;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\ProductPurchase;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\SubscriptionPurchase;
use RoundlyConsulting\Purchases\Results\GenericResult;
use Throwable;

class Google extends BaseProvider implements VerifiesConnectivity
{
    /** `voidedPurchaseNotification.refundType`: REFUND_TYPE_QUANTITY_BASED_PARTIAL_REFUND. */
    private const int PARTIAL_REFUND = 2;

    /** @var array<string, mixed> */
    protected readonly array $config;

    private ?GoogleClient $client;

    private readonly PushAuthenticator $push;

    public function __construct(?GoogleClient $client = null, ?PushAuthenticator $push = null)
    {
        /** @var array<string, mixed> $config */
        $config = config('purchases.settings.google');
        $this->config = $config;
        $this->client = $client;
        $this->push = $push ?? new PushAuthenticator;
    }

    /**
     * Verify a one-time in-app product purchase, optionally acknowledging it.
     */
    public function product(string $productId, string $token): ProductPurchase
    {
        $response = $this->client()->request()->get(
            "/androidpublisher/v3/applications/{$this->packageName()}/purchases/products/{$productId}/tokens/{$token}",
        );

        $purchase = ProductPurchase::fromRaw($response->json());

        if (! $purchase->isPurchased()) {
            throw VerificationException::because('Google product purchase is not in a purchased state.');
        }

        if ($this->shouldAcknowledge() && ! $purchase->isAcknowledged()) {
            $this->client()->request()->post(
                "/androidpublisher/v3/applications/{$this->packageName()}/purchases/products/{$productId}/tokens/{$token}:acknowledge",
            );
        }

        return $purchase;
    }

    /**
     * Verify a subscriptionsv2 purchase, optionally acknowledging it.
     */
    public function subscription(string $token): SubscriptionPurchase
    {
        $response = $this->client()->request()->get(
            "/androidpublisher/v3/applications/{$this->packageName()}/purchases/subscriptionsv2/tokens/{$token}",
        );

        $purchase = SubscriptionPurchase::fromRaw($response->json());

        if ($purchase->subscriptionState?->isTerminal() ?? true) {
            throw VerificationException::because('Google subscription is canceled or expired.');
        }

        if ($this->shouldAcknowledge() && ! $purchase->isAcknowledged()) {
            $this->acknowledgeSubscription($token);
        }

        return $purchase;
    }

    /**
     * Acknowledge a subscription purchase by token.
     */
    public function acknowledgeSubscription(string $token, ?string $subscriptionId = null): void
    {
        $path = $subscriptionId !== null
            ? "/androidpublisher/v3/applications/{$this->packageName()}/purchases/subscriptions/{$subscriptionId}/tokens/{$token}:acknowledge"
            : "/androidpublisher/v3/applications/{$this->packageName()}/purchases/subscriptionsv2/tokens/{$token}:acknowledge";

        $this->client()->request()->post($path);
    }

    /**
     * Decode a Real-time Developer Notification delivered through Cloud Pub/Sub.
     */
    public function notification(Request $request): DeveloperNotification
    {
        // Prove the push came from Google Pub/Sub before trusting a byte of it.
        /** @var array<string, mixed> $push */
        $push = is_array($this->config['push'] ?? null) ? $this->config['push'] : [];
        $this->push->authenticate($request, $push);

        $data = $request->input('message.data');

        if (! is_string($data) || $data === '') {
            throw VerificationException::because('Missing Google Pub/Sub message data.');
        }

        $decoded = json_decode($this->decodeMessageData($data), true);

        if (! is_array($decoded)) {
            throw VerificationException::because('Malformed Google developer notification payload.');
        }

        /** @var array<string, mixed> $decoded */
        return DeveloperNotification::fromRaw($decoded);
    }

    /**
     * Decode a Pub/Sub `message.data` body.
     *
     * This is wire format, not a signature: Cloud Pub/Sub delivers the payload as
     * padded standard base64, so that is tried first, with the URL-safe alphabet
     * as a fallback for hosts (and our own PayloadFactory) that forward the
     * envelope base64url-encoded. Both codecs are strict — a value outside either
     * alphabet is rejected rather than silently decoding to different bytes.
     */
    private function decodeMessageData(string $data): string
    {
        try {
            return Base64::decode($data);
        } catch (InvalidEncodingException) {
            //
        }

        try {
            return Base64Url::decode($data);
        } catch (InvalidEncodingException $e) {
            throw new VerificationException('Malformed Google developer notification payload.', previous: $e);
        }
    }

    /**
     * Verify a purchase token. Pass productId for one-time products; subscriptions
     * are looked up directly from the token.
     */
    public function callback(Request $request): ProductPurchase|SubscriptionPurchase
    {
        $token = (string) $request->input('purchaseToken');
        $productId = $request->input('productId');

        if ($token === '') {
            throw VerificationException::because('Missing Google purchase token.');
        }

        if (is_string($productId) && $productId !== '') {
            return $this->product($productId, $token);
        }

        return $this->subscription($token);
    }

    public function result(Request $request): ProviderResult
    {
        if (is_string($request->input('message.data'))) {
            return $this->notificationResult($request);
        }

        $purchase = $this->callback($request);
        $token = (string) $request->input('purchaseToken');

        if ($purchase instanceof ProductPurchase) {
            return new GenericResult(
                provider: $this->id(),
                type: ResultType::Purchase,
                providerId: $purchase->orderId ?? $token,
                status: Status::Completed,
                transactionId: $purchase->orderId,
                name: $purchase->productId,
                productId: $purchase->productId,
                // The one-time products resource carries no price.
                price: null,
                activeFrom: $purchase->purchaseTime,
                trialEndsAt: null,
                endsAt: null,
                items: [],
                raw: $purchase->raw,
            );
        }

        return new GenericResult(
            provider: $this->id(),
            type: ResultType::Subscription,
            // The purchase token is the subscription's stable identity (and what its RTDNs
            // carry); the order id changes with every renewal, so it is the transaction.
            providerId: $token,
            status: $purchase->subscriptionState?->status() ?? Status::Processing,
            transactionId: $purchase->latestOrderId,
            name: $purchase->productId(),
            productId: $purchase->productId(),
            price: $purchase->price(),
            activeFrom: $purchase->startTime,
            trialEndsAt: null,
            endsAt: $purchase->expiryTime(),
            items: [],
            raw: $purchase->raw,
        );
    }

    /**
     * Map a Real-time Developer Notification (voided purchase or subscription
     * lifecycle change) into a provider-agnostic result.
     */
    private function notificationResult(Request $request): ProviderResult
    {
        $notification = $this->notification($request);

        $voided = $notification->voidedPurchaseNotification;

        if ($voided !== null) {
            return new GenericResult(
                provider: $this->id(),
                type: ResultType::Refund,
                providerId: $voided->orderId ?? $voided->purchaseToken ?? '',
                // refundType 2 is a quantity-based partial refund of a multi-quantity
                // purchase: recorded, but the purchase itself stays completed.
                status: $voided->refundType === self::PARTIAL_REFUND ? Status::Completed : Status::Refunded,
                transactionId: $voided->orderId,
                raw: $notification->raw,
                refundReason: $voided->refundType !== null ? (string) $voided->refundType : null,
                chargeback: false,
            );
        }

        $subscription = $notification->subscriptionNotification;
        $type = $subscription?->notificationType;
        $token = $subscription?->purchaseToken;
        $subscriptionId = $subscription?->subscriptionId;

        return new GenericResult(
            provider: $this->id(),
            type: $this->notificationResultType($type),
            providerId: $token ?? '',
            status: $type?->status() ?? Status::Processing,
            transactionId: null,
            name: $subscriptionId,
            productId: $subscriptionId,
            raw: $notification->raw,
            refundReason: $type?->isRefund() === true ? $type->name : null,
        );
    }

    /**
     * A subscription RTDN of a known, state-changing type is applied to the subscription.
     * A test notification, a one-time product RTDN (it carries no order or price — verify
     * the token through callback() instead), an informational type and a type this
     * version does not know are audited as a Notification and never applied.
     */
    private function notificationResultType(?NotificationType $type): ResultType
    {
        return match (true) {
            $type === null, $type->isInformational() => ResultType::Notification,
            $type->isRefund() => ResultType::Refund,
            default => ResultType::Subscription,
        };
    }

    /**
     * Confirm the service-account credentials work by exchanging them for an
     * OAuth2 access token (the JWT-bearer grant).
     */
    public function verifyConnectivity(): ConnectivityResult
    {
        try {
            $this->client()->request();
        } catch (Throwable $e) {
            return ConnectivityResult::failed($e->getMessage());
        }

        return ConnectivityResult::ok('Google service-account credentials are valid.');
    }

    public function id(): string
    {
        return 'google';
    }

    private function shouldAcknowledge(): bool
    {
        return Config::for($this->config)->boolean('acknowledge', true);
    }

    private function packageName(): string
    {
        $packageName = $this->config['package_name'] ?? null;

        if (! is_string($packageName) || $packageName === '') {
            throw VerificationException::because('Google package name is not configured.');
        }

        return $packageName;
    }

    private function client(): GoogleClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        /** @var array<string, mixed> $serviceAccount */
        $serviceAccount = $this->config['service_account'] ?? [];

        $baseUrl = $this->config['base_url'] ?? 'https://androidpublisher.googleapis.com';

        return $this->client = new GoogleClient(
            credentials: ServiceAccountCredentials::fromConfig($serviceAccount),
            baseUrl: is_string($baseUrl) ? $baseUrl : 'https://androidpublisher.googleapis.com',
        );
    }
}
