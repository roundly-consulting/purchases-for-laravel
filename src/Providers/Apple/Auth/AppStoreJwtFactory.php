<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Auth;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use SensitiveParameter;

/**
 * Builds and signs the ES256 JWT used to authenticate App Store Server API calls.
 *
 * Apple's claim set and the `kid` header are ours; the ES256 JWS (including the
 * DER → raw `r‖s` signature encoding JOSE requires) is crypto's.
 *
 * @link https://developer.apple.com/documentation/appstoreserverapi/generating_json_web_tokens_for_api_requests
 */
final class AppStoreJwtFactory
{
    private const AUDIENCE = 'appstoreconnect-v1';

    /** Apple rejects tokens older than 60 minutes; 20 leaves generous headroom. */
    private const LIFETIME = 1200;

    public function __construct(
        private readonly Jws $jws = new Jws,
    ) {}

    public function create(
        string $keyId,
        string $issuerId,
        string $bundleId,
        #[SensitiveParameter] string $privateKey,
    ): string {
        $issuedAt = Carbon::now()->getTimestamp();

        try {
            return $this->jws->sign(
                ['kid' => $keyId],
                [
                    'iss' => $issuerId,
                    'iat' => $issuedAt,
                    'exp' => $issuedAt + self::LIFETIME,
                    'aud' => self::AUDIENCE,
                    'bid' => $bundleId,
                ],
                new Es(EcKey::private($privateKey)),
            );
        } catch (CryptoException $e) {
            throw new VerificationException('Invalid App Store Server API private key.', previous: $e);
        }
    }
}
