<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RoundingMode;
use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Contracts\VerifiesConnectivity;
use RoundlyConsulting\Purchases\DataTransferObjects\ConnectivityResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationSubType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Ownership;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ProductType;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptResponse;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptStatus;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;
use Throwable;

class Apple extends BaseProvider implements VerifiesConnectivity
{
    /** `price` is in milli-units of the currency. */
    private const int PRICE_SCALE = 3;

    /** `revocationPercentage` is a percentage in milli-units: 100000 = 100 %, i.e. scale 5. */
    private const int REVOCATION_SCALE = 5;

    private const int FULL_REVOCATION = 100_000;

    private const string FAMILY_REVOKE = 'FAMILY_REVOKE';

    /** verifyReceipt status 21007: a sandbox receipt sent to the production host. */
    private const int SANDBOX_RECEIPT = 21007;

    /** @var array<string, mixed> */
    protected readonly array $config;

    public function __construct(
        private readonly JwsManager $jws = new JwsManager,
        private readonly AppStoreServerApi $api = new AppStoreServerApi,
    ) {
        /** @var array<string, mixed> $config */
        $config = config('purchases.settings.apple');
        $this->config = $config;
    }

    /**
     * Verify a signed App Store Server notification — Apple's signature, then that it is
     * for the configured app (bundle id, environment and, in production, Apple ID).
     *
     * @throws VerificationException
     */
    public function notification(Request $request): ServerNotificationDecodedPayload
    {
        $signedPayload = (string) $request->input('signedPayload');

        $this->jws->verify($signedPayload);

        $claims = $this->jws->parse($signedPayload)->claims;

        if (Arr::has($claims, 'data.signedRenewalInfo')) {
            data_set($claims, 'data.renewalInfo', $this->jws->parse($claims['data']['signedRenewalInfo'])->claims);
        }

        if (Arr::has($claims, 'data.signedTransactionInfo')) {
            data_set($claims, 'data.transactionInfo', $this->jws->parse($claims['data']['signedTransactionInfo'])->claims);
        }

        $payload = ServerNotificationDecodedPayload::fromRaw($claims);

        // A genuine Apple signature says nothing about WHOSE app this is: Apple signs every
        // app's notifications with the same chain. Only this host's app is accepted.
        AppIdentity::fromConfig()->assertNotification($payload);

        return $payload;
    }

    /**
     * @deprecated Apple's verifyReceipt endpoint is deprecated. Prefer the
     *             App Store Server API via AppStoreServerApi::transaction().
     */
    public function callback(Request $request): mixed
    {
        $body = [
            'receipt-data' => $request->getContent(),
            'password' => $this->config['password'],
            'exclude-old-transactions' => false,
        ];

        $raw = $this->client()->asJson()->post('/verifyReceipt', $body)->json();
        $sandbox = $this->sandbox();

        // Apple: verify with production first, then with the sandbox on 21007 — a sandbox
        // receipt (App Review, TestFlight) sent to production.
        if (! $sandbox && is_array($raw) && ($raw['status'] ?? null) === self::SANDBOX_RECEIPT) {
            $raw = $this->client()->baseUrl($this->receiptHost(sandbox: true))->asJson()->post('/verifyReceipt', $body)->json();
            $sandbox = true;
        }

        return $this->receipt($raw, $sandbox);
    }

    /**
     * A verifyReceipt response, once its status says the receipt is valid. Apple marks
     * `environment` optional — an error response often carries only its `status` — so the
     * status decides first, and a valid receipt that names no environment is from the one of
     * the host that verified it.
     */
    private function receipt(mixed $raw, bool $sandbox): ReceiptResponse
    {
        if (! is_array($raw) || ! is_int($raw['status'] ?? null)) {
            throw VerificationException::because('Malformed App Store verifyReceipt response.');
        }

        $status = new ReceiptStatus($raw['status']);

        if (! $status->isValid()) {
            throw VerificationException::because($status->message());
        }

        if (Environment::tryFrom(is_string($raw['environment'] ?? null) ? $raw['environment'] : '') === null) {
            $raw['environment'] = ($sandbox ? Environment::Sandbox : Environment::Production)->value;
        }

        return ReceiptResponse::fromRaw($raw);
    }

    public function result(Request $request): ProviderResult
    {
        $payload = $this->notification($request);

        $transaction = $payload->transactionInfo;
        $status = $this->status($payload->type, $payload->subType);

        $type = $this->resultType($payload->type, $payload->subType, $transaction);

        // A purchase or subscription is keyed on its original transaction (a subscription
        // keeps it across renewals); a refund on the transaction it refunds, so refunds of
        // two periods of one subscription are two refunds, not one overwriting the other.
        $providerId = $type === ResultType::Refund
            ? ($transaction->transactionId ?? $transaction->originalTransactionId ?? $payload->uuid)
            : ($transaction->originalTransactionId ?? $transaction->transactionId ?? $payload->uuid);

        return new GenericResult(
            provider: $this->id(),
            type: $type,
            providerId: $providerId,
            status: $status,
            transactionId: $transaction?->transactionId,
            name: $transaction?->productId,
            productId: $transaction?->productId,
            price: $this->price($transaction, $payload->type),
            activeFrom: $transaction?->purchaseDate,
            trialEndsAt: null,
            // A refund ends at its revocation — that is the refund's date, not the period's end.
            endsAt: $type === ResultType::Refund
                ? ($transaction->revocationDate ?? $transaction?->expiresDate)
                : $transaction?->expiresDate,
            items: [],
            raw: $payload->toArray(),
            refundReason: $payload->type->isRefund() ? $payload->type->value : null,
            chargeback: false,
            // When Apple signed the notification — what orders redeliveries and replays.
            occurredAt: $payload->signedDate,
        );
    }

    /**
     * Apple reports the price in milli-units (USD 1.99 = `1990`); anything finer
     * than the currency's minor unit rounds half away from zero, once.
     *
     * A refund carries what was refunded: a prorated refund is its
     * `revocationPercentage` share of the price (milli-units × milli-percent, still
     * rounded once). A Family Sharing transaction — shared with, or revoked from, a
     * family member — moved no money on this account, so it carries none.
     */
    private function price(?TransactionInfo $transaction, NotificationType $type): ?Money
    {
        if ($transaction?->price === null || $transaction->currency === null) {
            return null;
        }

        if ($type === NotificationType::TypeRevoke
            || $transaction->revocationType === self::FAMILY_REVOKE
            || $transaction->inAppOwnershipType === Ownership::FamilyShared) {
            return null;
        }

        $amount = (string) $transaction->price;
        $scale = self::PRICE_SCALE;

        if ($type->isRefund() && $transaction->revocationPercentage !== null) {
            if ($transaction->revocationPercentage < 0 || $transaction->revocationPercentage > self::FULL_REVOCATION) {
                return null;
            }

            $amount = bcmul($amount, (string) $transaction->revocationPercentage);
            $scale += self::REVOCATION_SCALE;
        }

        try {
            return Money::ofScaled($amount, $scale, $transaction->currency, RoundingMode::HalfAwayFromZero);
        } catch (MoneyException) {
            return null;
        }
    }

    /**
     * An informational notification (and anything without a transaction) is a
     * Notification: audited, never applied. ONE_TIME_CHARGE is a consumable,
     * non-consumable or non-renewing purchase; a REFUND_REVERSED reinstates whichever
     * kind the transaction is. Everything else that carries a transaction concerns an
     * auto-renewable subscription.
     */
    private function resultType(NotificationType $type, ?NotificationSubType $subType, ?TransactionInfo $transaction): ResultType
    {
        if ($type->isRefund()) {
            return ResultType::Refund;
        }

        if ($transaction === null
            || ($type->isInformational() && ! $this->isImmediateUpgrade($type, $subType))
            || $this->isDeferredDowngrade($type, $subType)) {
            return ResultType::Notification;
        }

        return match (true) {
            $type === NotificationType::TypeOneTimeCharge => ResultType::Purchase,
            $type === NotificationType::TypeRefundReversed => $transaction->type === ProductType::AutoRenewableSubscription
                ? ResultType::Subscription
                : ResultType::Purchase,
            default => ResultType::Subscription,
        };
    }

    /**
     * DID_FAIL_TO_RENEW with a GRACE_PERIOD subtype keeps access through the grace
     * period; without one the subscription is in billing retry — access stops, but it
     * has not expired (Apple retries for 60 days), so it is held, not failed. An
     * UPGRADE starts the new plan's billing period at once.
     */
    private function status(NotificationType $type, ?NotificationSubType $subType): Status
    {
        if ($type === NotificationType::TypeDidFailToRenew) {
            return $subType === NotificationSubType::SubtypeGracePeriod
                ? Status::InGracePeriod
                : Status::OnHold;
        }

        if ($this->isImmediateUpgrade($type, $subType)) {
            return Status::Completed;
        }

        return $type->status();
    }

    private function isImmediateUpgrade(NotificationType $type, ?NotificationSubType $subType): bool
    {
        return $type === NotificationType::TypeDidChangeRenewalPref
            && $subType === NotificationSubType::SubtypeUpgrade;
    }

    /**
     * An offer that downgrades takes effect at the next renewal: until then the current
     * plan stays, so the notification changes nothing yet.
     */
    private function isDeferredDowngrade(NotificationType $type, ?NotificationSubType $subType): bool
    {
        return $type === NotificationType::TypeOfferRedeemed
            && $subType === NotificationSubType::SubtypeDowngrade;
    }

    /**
     * Confirm the App Store Server API credentials work by requesting a test
     * server notification (the canonical Apple connectivity check).
     */
    public function verifyConnectivity(): ConnectivityResult
    {
        try {
            $this->api->requestTestNotification();
        } catch (Throwable $e) {
            return ConnectivityResult::failed($e->getMessage());
        }

        return ConnectivityResult::ok('App Store Server API credentials are valid.');
    }

    protected function getBaseUrl(): string
    {
        return $this->receiptHost($this->sandbox());
    }

    private function sandbox(): bool
    {
        return Config::for(['purchases.settings.apple.sandbox' => $this->config['sandbox'] ?? null])
            ->boolean('purchases.settings.apple.sandbox');
    }

    private function receiptHost(bool $sandbox): string
    {
        /** @var array<string, mixed> $url */
        $url = $this->config['url'] ?? [];

        return $sandbox
            ? PurchasesConfig::string($url['sandbox'] ?? null, 'purchases.settings.apple.url.sandbox', 'https://sandbox.itunes.apple.com')
            : PurchasesConfig::string($url['live'] ?? null, 'purchases.settings.apple.url.live', 'https://buy.itunes.apple.com');
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->getBaseUrl())
            ->throw();
    }
}
