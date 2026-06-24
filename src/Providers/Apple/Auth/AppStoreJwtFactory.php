<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Auth;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Support\Base64Url;
use RoundlyConsulting\Purchases\Support\EcdsaSignature;
use SensitiveParameter;

/**
 * Builds and signs the ES256 JWT used to authenticate App Store Server API calls.
 *
 * @link https://developer.apple.com/documentation/appstoreserverapi/generating_json_web_tokens_for_api_requests
 */
final class AppStoreJwtFactory
{
    private const AUDIENCE = 'appstoreconnect-v1';

    public function create(
        string $keyId,
        string $issuerId,
        string $bundleId,
        #[SensitiveParameter] string $privateKey,
    ): string {
        $issuedAt = Carbon::now()->getTimestamp();

        $header = Base64Url::encode((string) json_encode([
            'alg' => 'ES256',
            'kid' => $keyId,
            'typ' => 'JWT',
        ]));

        $claims = Base64Url::encode((string) json_encode([
            'iss' => $issuerId,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 1200,
            'aud' => self::AUDIENCE,
            'bid' => $bundleId,
        ]));

        $signingInput = $header.'.'.$claims;

        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw VerificationException::because('Invalid App Store Server API private key.');
        }

        $der = '';

        if (! openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw VerificationException::because('Failed to sign the App Store Server API token.');
        }

        return $signingInput.'.'.Base64Url::encode(EcdsaSignature::fromDer($der));
    }
}
