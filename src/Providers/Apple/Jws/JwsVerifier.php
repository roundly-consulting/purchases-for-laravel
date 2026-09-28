<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Algorithm;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\X509\Certificate;
use RoundlyConsulting\Crypto\X509\Chain;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * Verifies an Apple App Store Server JWS payload.
 *
 * Two independent checks have to pass. First the *trust* decision, which is
 * Apple's and stays here: the signing certificate is taken from the token's own
 * `x5c` header, so it is only worth anything once the chain above it is pinned
 * to Apple's published intermediate and root (by fingerprint), each link is
 * proven to have signed the one below it, and the leaf and intermediate carry
 * Apple's App Store signing markers. Only then is the leaf's public key
 * used for the *algorithm* step — an ES256 JWS verification, pinned to ES256,
 * over the untouched compact token.
 *
 * crypto-for-laravel supplies the X.509 mathematics (parsing, fingerprints,
 * "is this signed by that"). It never rules on trust: which anchors are pinned,
 * how long the chain must be, and what an expired certificate means are all
 * decided here.
 */
class JwsVerifier
{
    /**
     * SHA-1 fingerprints of Apple's intermediate (WWDR CA G6) and root (Root CA G3)
     * certificates. The leaf certificate is verified against this chain at runtime.
     *
     * @var list<string>
     */
    protected const APPLE_CERTIFICATE_FINGERPRINTS = [
        // Fingerprint of https://www.apple.com/certificateauthority/AppleWWDRCAG6.cer
        '0be38bfe21fd434d8cc51cbe0e2bc7758ddbf97b',
        // Fingerprint of https://www.apple.com/certificateauthority/AppleRootCA-G3.cer
        'b52cb02fd567e0359fe8fa4d4c41037970fe01b0',
    ];

    protected const CHAIN_LENGTH = 3;

    /**
     * The marker extension Apple puts on the certificate that signs App Store data
     * (Mac App Store and App Store receipt signing). Apple's WWDR CA issues leaves for
     * many other purposes — code signing, push, Wallet — and none of those may sign a
     * notification. Apple's own ChainVerifier requires it.
     */
    protected const LEAF_OID = '1.2.840.113635.100.6.11.1';

    /**
     * The marker extension on Apple's Worldwide Developer Relations intermediate.
     */
    protected const INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    /**
     * Clock-skew tolerance used when the host has configured none.
     */
    protected const DEFAULT_CLOCK_SKEW = 60;

    /**
     * The largest clock skew that can be called clock skew. Beyond an hour the
     * value is not absorbing a drifting clock, it is switching the expiry check
     * off — so it is rejected as a misconfiguration rather than honoured.
     */
    protected const MAX_CLOCK_SKEW = 3600;

    public function __construct(
        private readonly Jws $jws = new Jws,
    ) {}

    /**
     * The trust anchors the chain is pinned to. Overridable only so a test can
     * pin its own throwaway CA; production always pins Apple's published
     * intermediate and root.
     *
     * @return list<string>
     */
    protected function fingerprints(): array
    {
        return static::APPLE_CERTIFICATE_FINGERPRINTS;
    }

    public function verify(DecodedToken $token): bool
    {
        $x5c = $token->certificateChain();

        if (count($x5c) !== self::CHAIN_LENGTH) {
            return false;
        }

        $chain = $this->chain($x5c);

        // The pinned anchors are the intermediate and the root; the leaf rotates
        // and is never pinned. `fingerprints()` is leaf → root, so the leaf is
        // sliced off before the comparison — same list, same order as before.
        if (array_slice($chain->fingerprints(HashAlgorithm::Sha1), 1) !== $this->fingerprints()) {
            return false;
        }

        // Fingerprints alone only prove the chain *carries* Apple's certificates.
        // Linkage proves each certificate was actually signed by the one above it,
        // which is what stops a rogue leaf smuggled under a genuine intermediate.
        if (! $chain->isLinked()) {
            return false;
        }

        // The chain is Apple's and internally consistent — but a certificate
        // outside its validity window is not acceptable, no matter who signed it.
        $this->assertWithinValidity($chain);

        // Apple's CA signs certificates for many purposes; only an App Store
        // data-signing leaf under the WWDR intermediate may sign a notification.
        if (! $this->carriesApplePolicyMarkers($chain)) {
            return false;
        }

        return $this->verifySignature($token, $chain->leaf());
    }

    /**
     * Whether the leaf and the intermediate carry the extensions Apple marks the App
     * Store signing certificate and the WWDR intermediate with — the certificate-policy
     * checks of Apple's reference ChainVerifier.
     */
    protected function carriesApplePolicyMarkers(Chain $chain): bool
    {
        return $chain->leaf()->extension(self::LEAF_OID) !== null
            && $chain->get(1)->extension(self::INTERMEDIATE_OID) !== null;
    }

    /**
     * Every certificate in the chain must be inside its RFC 5280 validity window,
     * give or take the configured clock skew.
     *
     * crypto reports the dates; the ruling that an expired certificate is
     * unacceptable is ours. It fails with its own message — an expired chain is
     * an Apple rotation (or a replayed, archived notification), not a forgery,
     * and the two must never be indistinguishable.
     */
    protected function assertWithinValidity(Chain $chain): void
    {
        $leeway = $this->clockSkewLeeway();

        foreach ($chain as $certificate) {
            $name = $certificate->commonName() ?? 'unknown';

            if ($certificate->isExpiredAt(leewaySeconds: $leeway)) {
                throw VerificationException::certificateExpired($name, $certificate->notAfter()->toIso8601String());
            }

            if ($certificate->isNotYetValidAt(leewaySeconds: $leeway)) {
                throw VerificationException::certificateNotYetValid($name, $certificate->notBefore()->toIso8601String());
            }
        }
    }

    /**
     * The configured clock-skew tolerance, in seconds, applied to both ends of
     * every certificate's validity window.
     *
     * The value is validated rather than coerced: a negative or absurdly large
     * skew would quietly weaken (or disable) the expiry check, so it fails loudly
     * instead of falling back to the default.
     *
     * @throws InvalidConfigurationException
     */
    protected function clockSkewLeeway(): int
    {
        $value = config('purchases.settings.apple.certificate_clock_skew', self::DEFAULT_CLOCK_SKEW);

        // env() hands back strings, so a value from the environment arrives as one.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0 || $value > self::MAX_CLOCK_SKEW) {
            throw InvalidConfigurationException::clockSkew($value, self::MAX_CLOCK_SKEW);
        }

        return $value;
    }

    /**
     * Parse the `x5c` header into a certificate chain.
     *
     * `x5c` is standard (padded) base64 of the DER — RFC 7515 §4.1.6, not
     * base64url. The header is attacker-controlled, so every crypto failure
     * (malformed base64, unreadable certificate, oversized entry) is translated
     * into this package's own exception rather than escaping as a CryptoException.
     *
     * @param  list<string>  $x5c
     */
    protected function chain(array $x5c): Chain
    {
        try {
            return Chain::fromX5c($x5c);
        } catch (CryptoException $e) {
            throw new VerificationException('Unable to read certificate from JWS header.', previous: $e);
        }
    }

    /**
     * Verify the token's ES256 signature with the (already trusted) leaf
     * certificate's public key. The algorithm is pinned to ES256 before the
     * signature is touched, so an `alg` swap in the header cannot downgrade it.
     */
    protected function verifySignature(DecodedToken $token, Certificate $certificate): bool
    {
        try {
            $key = $certificate->publicKey();

            if (! $key instanceof EcKey) {
                return false;
            }

            $this->jws->verify($token->compact, new Es($key), Algorithm::ES256);
        } catch (CryptoException) {
            return false;
        }

        return true;
    }
}
