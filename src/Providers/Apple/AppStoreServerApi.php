<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Auth\AppStoreJwtFactory;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

/**
 * The modern App Store Server API — the non-deprecated replacement for verifyReceipt.
 *
 * @link https://developer.apple.com/documentation/appstoreserverapi
 */
final class AppStoreServerApi
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
     * Ask Apple to send a test server notification — the canonical check that the
     * App Store Server API credentials are configured correctly. Returns the test
     * notification token Apple echoes back.
     */
    public function requestTestNotification(): string
    {
        $response = $this->client()->post('/inApps/v1/notifications/test');

        $token = $response->json('testNotificationToken');

        if (! is_string($token) || $token === '') {
            throw VerificationException::because('App Store Server API returned no test notification token.');
        }

        return $token;
    }

    /**
     * Look up a single transaction by id and decode its signed JWS payload — verified, and
     * refused unless it belongs to the configured app and environment.
     */
    public function transaction(string $transactionId): TransactionInfo
    {
        $response = $this->client()->get('/inApps/v1/transactions/'.rawurlencode($transactionId));

        $signed = $response->json('signedTransactionInfo');

        if (! is_string($signed) || $signed === '') {
            throw VerificationException::because('App Store Server API returned no signed transaction info.');
        }

        $this->jws->verify($signed);

        $transaction = TransactionInfo::fromRaw($this->jws->parse($signed)->claims);

        AppIdentity::fromConfig()->assertTransaction($transaction);

        return $transaction;
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
        $bundleId = $this->config['bundle_id'] ?? null;
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

        $sandbox = Config::for(['purchases.settings.apple.sandbox' => $this->config['sandbox'] ?? null])
            ->boolean('purchases.settings.apple.sandbox');

        return $sandbox
            ? PurchasesConfig::string($urls['sandbox'] ?? null, 'purchases.settings.apple.api.url.sandbox', 'https://api.storekit-sandbox.itunes.apple.com')
            : PurchasesConfig::string($urls['live'] ?? null, 'purchases.settings.apple.api.url.live', 'https://api.storekit.itunes.apple.com');
    }
}
