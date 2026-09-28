<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple;

use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;

/**
 * The one App Store app this host accepts signed data for: its bundle id, the environment
 * it runs in, and — in production — its Apple ID.
 *
 * Apple signs notifications for EVERY app on the App Store with the same certificate
 * chain, so a genuine signature proves only that Apple sent it, never that it concerns
 * this app. Without this binding a notification for someone else's app (a free sandbox
 * purchase of theirs, say, carrying a victim's `appAccountToken`) would verify and be
 * recorded here. Apple's own `SignedDataVerifier` makes the same three checks.
 *
 * @internal the Apple provider's trust policy — configure it through `purchases.settings.apple`.
 */
final readonly class AppIdentity
{
    public function __construct(
        public string $bundleId,
        public Environment $environment,
        public ?string $appAppleId = null,
    ) {}

    /**
     * @throws VerificationException when no bundle id is configured
     */
    public static function fromConfig(): self
    {
        $bundleId = config('purchases.settings.apple.bundle_id');

        if (! is_string($bundleId) || $bundleId === '') {
            throw VerificationException::because('Apple bundle id is not configured: set purchases.settings.apple.bundle_id (PURCHASES_APPLE_BUNDLE_ID). App Store data is only accepted for your own app.');
        }

        $appAppleId = config('purchases.settings.apple.app_apple_id');

        return new self(
            bundleId: $bundleId,
            environment: Config::boolean('purchases.settings.apple.sandbox') ? Environment::Sandbox : Environment::Production,
            appAppleId: is_int($appAppleId) || (is_string($appAppleId) && $appAppleId !== '') ? (string) $appAppleId : null,
        );
    }

    /**
     * Reject a notification that names another app, another environment, or (in
     * production) another Apple ID — in its app block and in every transaction or renewal
     * it carries.
     *
     * @throws VerificationException
     */
    public function assertNotification(ServerNotificationDecodedPayload $payload): void
    {
        if ($this->environment === Environment::Production && $this->appAppleId === null) {
            throw VerificationException::because('Apple app id is not configured: set purchases.settings.apple.app_apple_id (PURCHASES_APPLE_APP_APPLE_ID). Production notifications are bound to it.');
        }

        $metadata = $payload->appMetadata;

        if ($metadata !== null) {
            $this->assertApp($metadata->bundleId, $metadata->environment->value, $metadata->appAppleId);
        } else {
            // A notification without `data` carries its app in exactly one other block.
            // An external purchase token has no environment field; every other block does.
            [$block, $environment] = match (true) {
                $payload->summary !== null => [$payload->summary, self::string($payload->summary['environment'] ?? null)],
                $payload->appData !== null => [$payload->appData, self::string($payload->appData['environment'] ?? null)],
                $payload->externalPurchaseToken !== null => [$payload->externalPurchaseToken, $this->environment->value],
                default => throw VerificationException::because('Apple notification names no app.'),
            };

            $this->assertApp(self::string($block['bundleId'] ?? null), $environment, self::string($block['appAppleId'] ?? null));
        }

        if ($payload->transactionInfo !== null) {
            $this->assertTransaction($payload->transactionInfo);
        }

        if ($payload->renewalInfo !== null && $payload->renewalInfo->environment !== $this->environment) {
            throw VerificationException::because('Apple renewal info is for another environment.');
        }
    }

    /**
     * Reject a signed transaction that belongs to another app or environment.
     *
     * @throws VerificationException
     */
    public function assertTransaction(TransactionInfo $transaction): void
    {
        if ($transaction->bundleId !== $this->bundleId) {
            throw VerificationException::because('Apple transaction is for another app.');
        }

        if ($transaction->environment !== $this->environment) {
            throw VerificationException::because('Apple transaction is for another environment.');
        }
    }

    private function assertApp(?string $bundleId, ?string $environment, ?string $appAppleId): void
    {
        if ($bundleId !== $this->bundleId) {
            throw VerificationException::because('Apple notification is for another app.');
        }

        if ($environment !== $this->environment->value) {
            throw VerificationException::because('Apple notification is for another environment.');
        }

        // Apple sends no appAppleId in the sandbox; in production it must be ours.
        if ($this->environment === Environment::Production && $appAppleId !== $this->appAppleId) {
            throw VerificationException::because('Apple notification is for another app id.');
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_int($value) || (is_string($value) && $value !== '') ? (string) $value : null;
    }
}
