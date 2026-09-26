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
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptResponse;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Results\GenericResult;
use Throwable;

class Apple extends BaseProvider implements VerifiesConnectivity
{
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

        $type = $this->resultType($payload->type, $transaction !== null);

        return new GenericResult(
            provider: $this->id(),
            type: $type,
            providerId: $providerId,
            status: $status,
            transactionId: $transaction?->transactionId,
            name: $transaction?->productId,
            productId: $transaction?->productId,
            price: $this->price($transaction),
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
     */
    private function price(?TransactionInfo $transaction): ?Money
    {
        if ($transaction?->price === null || $transaction->currency === null) {
            return null;
        }

        try {
            return Money::ofScaled($transaction->price, 3, $transaction->currency, RoundingMode::HalfAwayFromZero);
        } catch (MoneyException) {
            return null;
        }
    }

    private function resultType(NotificationType $type, bool $hasTransaction): ResultType
    {
        if ($type->isRefund()) {
            return ResultType::Refund;
        }

        return $hasTransaction ? ResultType::Subscription : ResultType::Notification;
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
