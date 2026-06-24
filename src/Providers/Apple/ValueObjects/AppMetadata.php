<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Support\DataSet;

final class AppMetadata extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $appAppleId,
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

        return new self(
            appAppleId: $dataset->value('appAppleId'),
            bundleId: $dataset->value('bundleId'),
            bundleVersion: $dataset->value('bundleVersion'),
            environment: $dataset->enum('environment', Environment::class),
            raw: $dataset->retrieved(),
        );
    }
}
