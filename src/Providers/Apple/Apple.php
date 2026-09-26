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
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Contracts\VerifiesConnectivity;
use RoundlyConsulting\Purchases\DataTransferObjects\ConnectivityResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationSubType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Ownership;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ProductType;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptResponse;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Results\GenericResult;
use Throwable;

class Apple extends BaseProvider implements VerifiesConnectivity
{
    /** `price` is in milli-units of the currency. */
    private const int PRICE_SCALE = 3;

    /** `revocationPercentage` is a percentage in milli-units: 100000 = 100 %, i.e. scale 5. */
    private const int REVOCATION_SCALE = 5;

    private const int FULL_REVOCATION = 100_000;

    private const string FAMILY_REVOKE = 'FAMILY_REVOKE';

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

        return ServerNotificationDecodedPayload::fromRaw($claims);
    }

    /**
     * @deprecated Apple's verifyReceipt endpoint is deprecated. Prefer the
     *             App Store Server API via AppStoreServerApi::transaction().
     */
    public function callback(Request $request): mixed
    {
        $response = $this->client()->asJson()->post('/verifyReceipt', [
            'receipt-data' => $request->getContent(),
            'password' => $this->config['password'],
            'exclude-old-transactions' => false,
        ]);

        $receipt = ReceiptResponse::fromRaw($response->json());

        if (! $receipt->status->isValid()) {
            throw VerificationException::because($receipt->status->message());
        }

        return $receipt;
    }

    public function result(Request $request): ProviderResult
    {
        $payload = $this->notification($request);

        $transaction = $payload->transactionInfo;
        $status = $this->status($payload->type, $payload->subType);

        $providerId = $payload->uuid;

        if ($transaction !== null) {
            $providerId = $transaction->originalTransactionId
                ?? $transaction->transactionId
                ?? $payload->uuid;
        }

        $type = $this->resultType($payload->type, $transaction);

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
            endsAt: $transaction?->expiresDate,
            items: [],
            raw: $payload->toArray(),
            refundReason: $payload->type->isRefund() ? $payload->type->value : null,
            chargeback: false,
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
     * ONE_TIME_CHARGE is a consumable, non-consumable or non-renewing purchase; a
     * REFUND_REVERSED reinstates whichever kind the transaction is. Everything else that
     * carries a transaction concerns an auto-renewable subscription.
     */
    private function resultType(NotificationType $type, ?TransactionInfo $transaction): ResultType
    {
        if ($type->isRefund()) {
            return ResultType::Refund;
        }

        if ($transaction === null) {
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
     * Apple signals a billing-retry grace period through DID_FAIL_TO_RENEW with a
     * GRACE_PERIOD subtype; without it the renewal has genuinely failed.
     */
    private function status(NotificationType $type, ?NotificationSubType $subType): Status
    {
        if ($type === NotificationType::TypeDidFailToRenew) {
            return $subType === NotificationSubType::SubtypeGracePeriod
                ? Status::InGracePeriod
                : Status::Failed;
        }

        return $type->status();
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
        return $this->config['sandbox'] ? $this->config['url']['sandbox'] : $this->config['url']['live'];
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->getBaseUrl())
            ->throw();
    }
}
