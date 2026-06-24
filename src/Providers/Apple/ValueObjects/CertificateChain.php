<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use OpenSSLCertificate;

class CertificateChain
{
    public function __construct(
        public readonly OpenSSLCertificate $leaf,
        public readonly OpenSSLCertificate $intermediate,
        public readonly OpenSSLCertificate $root,
    ) {}

    /**
     * @param  list<string>  $fingerprint
     */
    public function fingerprintIs(array $fingerprint): bool
    {
        return $this->fingerprint() === $fingerprint;
    }

    /**
     * @return list<string|false>
     */
    public function fingerprint(): array
    {
        return [
            openssl_x509_fingerprint($this->intermediate),
            openssl_x509_fingerprint($this->root),
        ];
    }

    public function leafIsValid(): bool
    {
        return openssl_x509_verify($this->leaf, $this->intermediate) === 1;
    }

    public function intermediateIsValid(): bool
    {
        return openssl_x509_verify($this->intermediate, $this->root) === 1;
    }
}
