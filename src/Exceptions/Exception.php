<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions;

class Exception extends \Exception
{
    final public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function because(string $message, int $code = 0): static
    {
        return new static($message, $code);
    }
}
