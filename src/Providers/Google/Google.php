<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google;

use Illuminate\Http\Request;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\DeveloperNotification;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\ProductPurchase;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\SubscriptionPurchase;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\Base64Url;

class Google extends BaseProvider
{
    /** @var array<string, mixed> */
    protected readonly array $config;

    private ?GoogleClient $client;

    public function __construct(?GoogleClient $client = null)
    {
        /** @var array<string, mixed> $config */
        $config = config('purchases.settings.google');
        $this->config = $config;
        $this->client = $client;
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
        $data = $request->input('message.data');

        if (! is_string($data) || $data === '') {
            throw VerificationException::because('Missing Google Pub/Sub message data.');
        }

        $decoded = json_decode(Base64Url::decode($data), true);

        if (! is_array($decoded)) {
            throw VerificationException::because('Malformed Google developer notification payload.');
        }

        /** @var array<string, mixed> $decoded */
        return DeveloperNotification::fromRaw($decoded);
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
            providerId: $purchase->latestOrderId ?? $token,
            status: $purchase->subscriptionState?->status() ?? Status::Processing,
            transactionId: $purchase->latestOrderId,
            name: $purchase->productId(),
            productId: $purchase->productId(),
            price: null,
            activeFrom: $purchase->startTime,
            trialEndsAt: null,
            endsAt: $purchase->expiryTime(),
            items: [],
            raw: $purchase->raw,
        );
    }

    public function id(): string
    {
        return 'google';
    }

    private function shouldAcknowledge(): bool
    {
        return (bool) ($this->config['acknowledge'] ?? true);
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
