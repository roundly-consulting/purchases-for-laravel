<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Auth;

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;
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

        if (! is_string($clientEmail) || PurchasesConfig::blank($clientEmail)
            || ! is_string($privateKey) || PurchasesConfig::blank($privateKey)) {
            throw VerificationException::because('Google service-account credentials are not configured.');
        }

        return new self(
            clientEmail: $clientEmail,
            privateKey: $privateKey,
            tokenUri: PurchasesConfig::string($config['token_uri'] ?? null, 'purchases.settings.google.service_account.token_uri', 'https://oauth2.googleapis.com/token'),
        );
    }
}
