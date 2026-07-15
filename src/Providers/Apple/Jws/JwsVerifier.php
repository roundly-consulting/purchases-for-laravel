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
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * Verifies an Apple App Store Server JWS payload.
 *
 * Two independent checks have to pass. First the *trust* decision, which is
 * Apple's and stays here: the signing certificate is taken from the token's own
 * `x5c` header, so it is only worth anything once the chain above it is pinned
 * to Apple's published intermediate and root (by fingerprint) and each link is
 * proven to have signed the one below it. Only then is the leaf's public key
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

        return $this->verifySignature($token, $chain->leaf());
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
