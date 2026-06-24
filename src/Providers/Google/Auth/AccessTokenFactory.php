<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Support\Base64Url;

/**
 * Mints (and caches) Google OAuth2 access tokens using the JWT-bearer grant,
 * built natively on ext-openssl and Laravel's Http client — no google/apiclient.
 */
class AccessTokenFactory
{
    private const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private const CACHE_KEY = 'purchases:google:token';

    public function token(ServiceAccountCredentials $credentials): string
    {
        /** @var string */
        return Cache::remember(
            self::cacheKey($credentials),
            $this->ttl(),
            fn (): string => $this->request($credentials),
        );
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

        $header = Base64Url::encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = Base64Url::encode((string) json_encode([
            'iss' => $credentials->clientEmail,
            'scope' => self::SCOPE,
            'aud' => $credentials->tokenUri,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ]));

        $signingInput = $header.'.'.$claims;
        $signature = '';

        $key = openssl_pkey_get_private($credentials->privateKey);

        if ($key === false) {
            throw VerificationException::because('Invalid Google service-account private key.');
        }

        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw VerificationException::because('Failed to sign the Google JWT assertion.');
        }

        return $signingInput.'.'.Base64Url::encode($signature);
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
