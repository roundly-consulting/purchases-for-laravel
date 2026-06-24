<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

final class OneTimeProductNotification implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $version,
        public readonly ?int $notificationType,
        public readonly ?string $purchaseToken,
        public readonly ?string $sku,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            version: $dataset->value('version'),
            notificationType: $dataset->int('notificationType'),
            purchaseToken: $dataset->value('purchaseToken'),
            sku: $dataset->value('sku'),
            raw: $raw,
        );
    }
}
