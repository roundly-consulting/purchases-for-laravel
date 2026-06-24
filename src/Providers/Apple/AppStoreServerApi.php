<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Auth\AppStoreJwtFactory;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;

/**
 * The modern App Store Server API — the non-deprecated replacement for verifyReceipt.
 *
 * @link https://developer.apple.com/documentation/appstoreserverapi
 */
class AppStoreServerApi
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(
        private readonly JwsManager $jws = new JwsManager,
        private readonly AppStoreJwtFactory $tokens = new AppStoreJwtFactory,
    ) {
        /** @var array<string, mixed> $config */
        $config = config('purchases.settings.apple');
        $this->config = $config;
    }

    /**
     * Look up a single transaction by id and decode its signed JWS payload.
     */
    public function transaction(string $transactionId): TransactionInfo
    {
        $response = $this->client()->get("/inApps/v1/transactions/{$transactionId}");

        $signed = $response->json('signedTransactionInfo');

        if (! is_string($signed) || $signed === '') {
            throw VerificationException::because('App Store Server API returned no signed transaction info.');
        }

        $this->jws->verify($signed);

        return TransactionInfo::fromRaw($this->jws->parse($signed)->claims);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->token())
            ->throw();
    }

    private function token(): string
    {
        /** @var array<string, mixed> $api */
        $api = $this->config['api'] ?? [];

        $keyId = $api['key_id'] ?? null;
        $issuerId = $api['issuer_id'] ?? null;
        $bundleId = $api['bundle_id'] ?? null;
        $privateKey = $api['private_key'] ?? null;

        if (! is_string($keyId) || ! is_string($issuerId) || ! is_string($bundleId) || ! is_string($privateKey)
            || $keyId === '' || $issuerId === '' || $bundleId === '' || $privateKey === '') {
            throw VerificationException::because('App Store Server API credentials are not configured.');
        }

        return $this->tokens->create($keyId, $issuerId, $bundleId, $privateKey);
    }

    private function baseUrl(): string
    {
        /** @var array<string, mixed> $api */
        $api = $this->config['api'] ?? [];
        /** @var array<string, mixed> $urls */
        $urls = $api['url'] ?? [];

        $sandbox = (bool) ($this->config['sandbox'] ?? true);
        $url = $sandbox ? ($urls['sandbox'] ?? null) : ($urls['live'] ?? null);

        if (! is_string($url) || $url === '') {
            return $sandbox
                ? 'https://api.storekit-sandbox.itunes.apple.com'
                : 'https://api.storekit.itunes.apple.com';
        }

        return $url;
    }
}
