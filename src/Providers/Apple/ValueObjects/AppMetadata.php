<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * `appAppleId` is an int64 on the wire and absent in the sandbox; it is kept as a string
 * (or null) so an id is never subject to integer overflow on the way through.
 *
 * @link https://developer.apple.com/documentation/appstoreservernotifications/data
 */
final class AppMetadata extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $appAppleId,
        public readonly string $bundleId,
        public readonly string $bundleVersion,
        public readonly Environment $environment,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        $appAppleId = $dataset->value('appAppleId');

        return new self(
            appAppleId: is_int($appAppleId) || (is_string($appAppleId) && $appAppleId !== '') ? (string) $appAppleId : null,
            bundleId: $dataset->value('bundleId'),
            bundleVersion: $dataset->value('bundleVersion'),
            environment: $dataset->enum('environment', Environment::class),
            raw: $dataset->retrieved(),
        );
    }
}
