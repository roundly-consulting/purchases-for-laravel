<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptResponse;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;
use RoundlyConsulting\Purchases\Providers\BaseProvider;
use RoundlyConsulting\Purchases\Results\GenericResult;

class Apple extends BaseProvider
{
    /** @var array<string, mixed> */
    protected readonly array $config;

    public function __construct(
        private readonly JwsManager $jws = new JwsManager,
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
        $status = $payload->type->status();

        $providerId = $payload->uuid;

        if ($transaction !== null) {
            $providerId = $transaction->originalTransactionId
                ?? $transaction->transactionId
                ?? $payload->uuid;
        }

        return new GenericResult(
            provider: $this->id(),
            type: $transaction !== null ? ResultType::Subscription : ResultType::Notification,
            providerId: $providerId,
            status: $status,
            transactionId: $transaction?->transactionId,
            name: $transaction?->productId,
            productId: $transaction?->productId,
            price: null,
            activeFrom: $transaction?->purchaseDate,
            trialEndsAt: null,
            endsAt: $transaction?->expiresDate,
            items: [],
            raw: $payload->toArray(),
        );
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
