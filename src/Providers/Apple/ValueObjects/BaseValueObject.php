<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
abstract class BaseValueObject implements Arrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $vars = get_object_vars($this);

        if (array_key_exists('raw', $vars) && is_array($vars['raw'])) {
            /** @var array<string, mixed> */
            return $vars['raw'];
        }

        return $vars;
    }
}
