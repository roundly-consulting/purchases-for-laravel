<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\Enums\AutoRenewStatus;
use RoundlyConsulting\Purchases\Support\DataSet;

final class AutoRenew extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $autoRenewProductId,
        public readonly ?AutoRenewStatus $autoRenewStatus,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            autoRenewProductId: $dataset->value('autoRenewProductId'),
            autoRenewStatus: $dataset->enum('autoRenewStatus', AutoRenewStatus::class),
            raw: $dataset->retrieved(),
        );
    }
}
