<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * Mints (and caches) Google OAuth2 access tokens using the JWT-bearer grant.
 *
 * The grant, the scope, and the caching are ours; the RS256 JWS assertion is
 * crypto's.
 */
class AccessTokenFactory
{
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const CACHE_KEY = 'purchases:google:token';

    public function __construct(
        private readonly Jws $jws = new Jws,
    ) {}

    public function token(ServiceAccountCredentials $credentials): string
    {
        /** @var string */
        return Cache::remember(
            self::cacheKey($credentials),
            $this->ttl(),
            fn (): string => $this->request($credentials),
        );
    }

    /**
     * Exchange the credentials for a new token now, bypassing (and refreshing) the cache —
     * proof the credentials work today, not that they worked within the last hour.
     */
    public function fresh(ServiceAccountCredentials $credentials): string
    {
        $token = $this->request($credentials);

        Cache::put(self::cacheKey($credentials), $token, $this->ttl());

        return $token;
    }

    private function request(ServiceAccountCredentials $credentials): string
    {
        $assertion = $this->assertion($credentials);

        $response = Http::asForm()
            ->throw()
            ->post($credentials->tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw VerificationException::because('Google token endpoint did not return an access token.');
        }

        return $accessToken;
    }

    public function assertion(ServiceAccountCredentials $credentials): string
    {
        $issuedAt = Carbon::now()->getTimestamp();

        try {
            return $this->jws->sign(
                [],
                [
                    'iss' => $credentials->clientEmail,
                    'scope' => self::SCOPE,
                    'aud' => $credentials->tokenUri,
                    'iat' => $issuedAt,
                    'exp' => $issuedAt + 3600,
                ],
                new Rs(RsaKey::private($credentials->privateKey)),
            );
        } catch (CryptoException $e) {
            throw new VerificationException('Invalid Google service-account private key.', previous: $e);
        }
    }

    private function ttl(): int
    {
        // Refresh a minute before Google's hour-long expiry to avoid edge races.
        return 3540;
    }

    private static function cacheKey(ServiceAccountCredentials $credentials): string
    {
        return self::CACHE_KEY.':'.sha1($credentials->clientEmail);
    }
}
