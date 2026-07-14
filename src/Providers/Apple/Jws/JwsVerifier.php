<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

use OpenSSLCertificate;
use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Algorithm;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\CertificateChain;

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

        $chain = $this->getCertificatesChain($x5c);

        if (! $chain->fingerprintIs($this->fingerprints()) ||
            ! $chain->leafIsValid() ||
            ! $chain->intermediateIsValid()) {
            return false;
        }

        return $this->verifySignature($token, $chain->leaf);
    }

    /**
     * @param  list<string>  $certificates
     */
    protected function getCertificatesChain(array $certificates): CertificateChain
    {
        return new CertificateChain(
            leaf: $this->getOpenSslCertificate($certificates[0]),
            intermediate: $this->getOpenSslCertificate($certificates[1]),
            root: $this->getOpenSslCertificate($certificates[2]),
        );
    }

    protected function getOpenSslCertificate(string $certificate): OpenSSLCertificate
    {
        $contents =
            '-----BEGIN CERTIFICATE-----'.PHP_EOL.
            chunk_split($certificate, 64, PHP_EOL).
            '-----END CERTIFICATE-----';

        // Silenced deliberately: a malformed `x5c` entry is attacker-controlled
        // input, so it must surface as our VerificationException, not a warning.
        $resource = @openssl_x509_read($contents);

        if ($resource === false) {
            throw VerificationException::because('Unable to read certificate from JWS header.');
        }

        return $resource;
    }

    /**
     * Verify the token's ES256 signature with the (already trusted) leaf
     * certificate's public key. The algorithm is pinned to ES256 before the
     * signature is touched, so an `alg` swap in the header cannot downgrade it.
     */
    protected function verifySignature(DecodedToken $token, OpenSSLCertificate $certificate): bool
    {
        if (! openssl_x509_export($certificate, $pem)) {
            return false;
        }

        try {
            $this->jws->verify($token->compact, new Es(EcKey::public($pem)), Algorithm::ES256);
        } catch (CryptoException) {
            return false;
        }

        return true;
    }
}
