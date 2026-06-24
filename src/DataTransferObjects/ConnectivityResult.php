<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

final readonly class ConnectivityResult
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}

    public static function ok(string $message = 'Credentials verified.'): self
    {
        return new self(true, $message);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
