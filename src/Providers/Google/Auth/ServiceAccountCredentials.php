<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Auth;

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use SensitiveParameter;

/**
 * Google Cloud service-account credentials used to mint OAuth2 access tokens.
 */
final readonly class ServiceAccountCredentials
{
    public function __construct(
        public string $clientEmail,
        #[SensitiveParameter] public string $privateKey,
        public string $tokenUri = 'https://oauth2.googleapis.com/token',
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $clientEmail = $config['client_email'] ?? null;
        $privateKey = $config['private_key'] ?? null;

        if (! is_string($clientEmail) || $clientEmail === '' || ! is_string($privateKey) || $privateKey === '') {
            throw VerificationException::because('Google service-account credentials are not configured.');
        }

        $tokenUri = $config['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        return new self(
            clientEmail: $clientEmail,
            privateKey: $privateKey,
            tokenUri: is_string($tokenUri) ? $tokenUri : 'https://oauth2.googleapis.com/token',
        );
    }
}
