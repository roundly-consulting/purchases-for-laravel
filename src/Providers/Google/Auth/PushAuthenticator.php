<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Hash\ConstantTime;
use RoundlyConsulting\Crypto\Jose\Claims;
use RoundlyConsulting\Crypto\Jose\Jwk;
use RoundlyConsulting\Crypto\Jose\JwkKeyType;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Algorithm;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use Throwable;

/**
 * Proves a Real-time Developer Notification push really comes from Google Cloud Pub/Sub
 * before a byte of it is trusted. Fail-closed: with authentication on (the default) and
 * nothing configured, every push is rejected.
 *
 * Two mechanisms, each enforced when configured (both may be):
 *
 * - **OIDC** — the push subscription's "Enable authentication": Pub/Sub sends
 *   `Authorization: Bearer <JWT>`, RS256-signed by Google. The signature is checked
 *   against Google's JWKS (fetched and cached; re-fetched, at most once a minute, when a
 *   `kid` is unknown), then `iss`, `aud` (the configured audience), `email` (the
 *   configured push service account), `email_verified` and `exp`/`iat`.
 * - **URL token** — a shared secret appended to the push endpoint (`?token=…`),
 *   compared in constant time.
 *
 * The trust decisions are ours; every primitive (JWK, RS256, the JWS itself,
 * constant-time compare) is crypto-for-laravel's.
 *
 * @link https://cloud.google.com/pubsub/docs/authenticate-push-subscriptions
 */
final class PushAuthenticator
{
    /** Clock skew tolerated on `exp` / `iat`, in seconds. */
    public const int LEEWAY = 60;

    /** The shortest interval between two JWKS fetches forced by an unknown `kid`. */
    public const int MIN_REFRESH_SECONDS = 60;

    /** The two issuer spellings Google uses in its ID tokens. */
    public const array ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public const string CACHE_KEY = 'purchases:google:push:jwks';

    public function __construct(
        private readonly Jws $jws = new Jws,
    ) {}

    /**
     * @param  array<string, mixed>  $config  `purchases.settings.google.push`
     *
     * @throws VerificationException
     */
    public function authenticate(Request $request, array $config): void
    {
        if (($config['authenticate'] ?? true) === false) {
            return;
        }

        $audience = self::string($config['audience'] ?? null);
        $serviceAccount = self::string($config['service_account_email'] ?? null);
        $token = self::string($config['token'] ?? null);

        if ($audience === null && $serviceAccount === null && $token === null) {
            throw VerificationException::because('Google push authentication is not configured: set purchases.settings.google.push.audience and service_account_email (and/or token).');
        }

        if (($audience === null) !== ($serviceAccount === null)) {
            throw VerificationException::because('Google push OIDC authentication needs both purchases.settings.google.push.audience and service_account_email.');
        }

        if ($token !== null) {
            $this->assertUrlToken($request, $token);
        }

        if ($audience !== null && $serviceAccount !== null) {
            $this->assertOidc($request, $config, $audience, $serviceAccount);
        }
    }

    private function assertUrlToken(Request $request, string $expected): void
    {
        $given = $request->query('token');

        if (! is_string($given) || ! ConstantTime::equals($expected, $given)) {
            throw VerificationException::because('Google push token is missing or does not match.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertOidc(Request $request, array $config, string $audience, string $serviceAccount): void
    {
        $bearer = $request->bearerToken();

        if (! is_string($bearer) || $bearer === '') {
            throw VerificationException::because('Google push request carries no OIDC bearer token.');
        }

        try {
            $claims = $this->jws->verify($bearer, $this->key($bearer, $config)->publicKey()->verifier(), Algorithm::RS256);
            $claims->assertTemporal(self::LEEWAY);
        } catch (CryptoException $e) {
            throw new VerificationException('Google push OIDC token is invalid.', previous: $e);
        }

        $this->assertClaims($claims, $audience, $serviceAccount);
    }

    private function assertClaims(Claims $claims, string $audience, string $serviceAccount): void
    {
        if (! in_array($claims->get('iss'), self::ISSUERS, true)) {
            throw VerificationException::because('Google push OIDC token was not issued by Google.');
        }

        $aud = $claims->get('aud');

        if (! (is_string($aud) && ConstantTime::equals($audience, $aud))) {
            throw VerificationException::because('Google push OIDC token has an unexpected audience.');
        }

        $email = $claims->get('email');

        if (! is_string($email) || strtolower($email) !== strtolower($serviceAccount)) {
            throw VerificationException::because('Google push OIDC token was not issued to the configured service account.');
        }

        if ($claims->get('email_verified') !== true) {
            throw VerificationException::because('Google push OIDC token carries an unverified email.');
        }
    }

    /**
     * The JWK named by the token's `kid`, from Google's (cached) key set.
     *
     * @param  array<string, mixed>  $config
     */
    private function key(string $token, array $config): Jwk
    {
        $kid = $this->kid($token);
        $keys = $this->keys($config, refresh: false);

        if (! array_key_exists($kid, $keys)) {
            $keys = $this->keys($config, refresh: true);
        }

        if (! array_key_exists($kid, $keys)) {
            throw VerificationException::because('Google push OIDC token is signed by an unknown key.');
        }

        try {
            $jwk = Jwk::fromArray($keys[$kid]);
        } catch (CryptoException $e) {
            throw new VerificationException('Google published a malformed signing key.', previous: $e);
        }

        if ($jwk->keyType() !== JwkKeyType::Rsa) {
            throw VerificationException::because('Google push OIDC token is signed by a non-RSA key.');
        }

        return $jwk;
    }

    /**
     * The `kid` from the (not yet verified) header — used only to pick the key; the
     * signature, the algorithm pin and every claim are checked afterwards.
     */
    private function kid(string $token): string
    {
        $header = explode('.', $token, 2)[0];

        try {
            $decoded = json_decode(Base64Url::decode($header), true, 4, JSON_THROW_ON_ERROR);
        } catch (CryptoException|JsonException $e) {
            throw new VerificationException('Google push OIDC token is malformed.', previous: $e);
        }

        $kid = is_array($decoded) ? ($decoded['kid'] ?? null) : null;

        if (! is_string($kid) || $kid === '') {
            throw VerificationException::because('Google push OIDC token names no signing key.');
        }

        return $kid;
    }

    /**
     * Google's signing keys by `kid`. Cached for `jwks_cache_ttl` seconds; a refresh
     * (an unknown `kid` — Google rotated) re-fetches at most once per MIN_REFRESH_SECONDS,
     * so a stream of forged `kid`s cannot turn us into a request amplifier.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, array<array-key, mixed>>
     */
    private function keys(array $config, bool $refresh): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $now = Carbon::now()->getTimestamp();

        if (is_array($cached) && is_array($cached['keys'] ?? null) && is_int($cached['fetched_at'] ?? null)) {
            /** @var array<string, array<array-key, mixed>> $keys */
            $keys = $cached['keys'];

            if (! $refresh || $now - $cached['fetched_at'] < self::MIN_REFRESH_SECONDS) {
                return $keys;
            }
        }

        $keys = $this->fetch($config);

        $ttl = $config['jwks_cache_ttl'] ?? 3600;

        Cache::put(self::CACHE_KEY, ['fetched_at' => $now, 'keys' => $keys], is_numeric($ttl) ? max(1, (int) $ttl) : 3600);

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array<array-key, mixed>>
     */
    private function fetch(array $config): array
    {
        $url = self::string($config['jwks_url'] ?? null) ?? 'https://www.googleapis.com/oauth2/v3/certs';

        try {
            $body = Http::timeout(10)->throw()->get($url)->json();
        } catch (Throwable $e) {
            throw new VerificationException('Could not fetch Google\'s signing keys.', previous: $e);
        }

        $keys = [];

        foreach (is_array($body) && is_array($body['keys'] ?? null) ? $body['keys'] : [] as $key) {
            if (is_array($key) && is_string($key['kid'] ?? null) && $key['kid'] !== '') {
                $keys[$key['kid']] = $key;
            }
        }

        if ($keys === []) {
            throw VerificationException::because('Google\'s signing-key set is empty or malformed.');
        }

        return $keys;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
