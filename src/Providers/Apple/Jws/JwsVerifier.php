<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Jws;

use OpenSSLCertificate;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\CertificateChain;
use RoundlyConsulting\Purchases\Support\EcdsaSignature;

/**
 * Verifies the ES256 signature of an Apple App Store Server JWS payload using
 * only ext-openssl. The signing certificate is taken from the token's `x5c`
 * header and validated against Apple's published certificate-authority chain.
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

    public function verify(DecodedToken $token): bool
    {
        $x5c = $token->certificateChain();

        if (count($x5c) !== self::CHAIN_LENGTH) {
            return false;
        }

        $chain = $this->getCertificatesChain($x5c);

        if (! $chain->fingerprintIs(self::APPLE_CERTIFICATE_FINGERPRINTS) ||
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

        $resource = openssl_x509_read($contents);

        if ($resource === false) {
            throw VerificationException::because('Unable to read certificate from JWS header.');
        }

        return $resource;
    }

    protected function verifySignature(DecodedToken $token, OpenSSLCertificate $certificate): bool
    {
        $publicKey = openssl_pkey_get_public($certificate);

        if ($publicKey === false) {
            return false;
        }

        // JWS ES256 signatures are raw R||S concatenations; openssl_verify needs DER.
        $derSignature = EcdsaSignature::toDer($token->signature);

        $result = openssl_verify(
            $token->signingInput,
            $derSignature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        return $result === 1;
    }
}
