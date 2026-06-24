<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;

/**
 * Thin reader over a decoded JSON payload that tracks which keys were consumed,
 * so value objects can retain only the data they actually mapped.
 */
class DataSet
{
    /** @var list<string> */
    protected array $retrieved = [];

    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public array $raw,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function retrieved(): array
    {
        return Arr::only($this->raw, $this->retrieved);
    }

    public function value(string $key, mixed $default = null): mixed
    {
        $this->markAsRetrieved($key);

        return Arr::get($this->raw, $key, $default);
    }

    public function int(string $key, mixed $default = null): mixed
    {
        $this->markAsRetrieved($key);

        if (Arr::has($this->raw, $key)) {
            return (int) Arr::get($this->raw, $key);
        }

        return value($default);
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|mixed
     */
    public function enum(string $key, string $enum, mixed $default = null): mixed
    {
        $this->markAsRetrieved($key);

        $value = $this->raw[$key] ?? null;

        if ($value === null) {
            return value($default);
        }

        if (! is_string($value) && ! is_int($value)) {
            return value($default);
        }

        return $enum::tryFrom($value) ?? value($default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $this->markAsRetrieved($key);

        $value = $this->value($key, $default);

        if (is_string($value)) {
            return strtolower($value) === 'true';
        }

        return (bool) $value;
    }

    public function datetime(string $key): ?Carbon
    {
        $this->markAsRetrieved($key);

        $value = $this->value($key);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestampMs((int) $value);
    }

    /**
     * Map a list of raw payloads to value objects via their fromRaw factory.
     *
     * @template TItem of FromRaw
     *
     * @param  class-string<TItem>  $className
     * @return list<TItem>
     */
    public function arrayOf(string $key, string $className): array
    {
        $items = $this->value($key, []);

        if (! is_array($items)) {
            return [];
        }

        $mapped = [];

        foreach ($items as $item) {
            $mapped[] = $className::fromRaw(is_array($item) ? $item : []);
        }

        return $mapped;
    }

    /**
     * @param  class-string  $className
     */
    public function valueOf(string $key, string $className, mixed $default = null): mixed
    {
        if (Arr::has($this->raw, $key)) {
            return new $className($this->value($key));
        }

        return value($default);
    }

    /**
     * @param  class-string<FromRaw>  $className
     */
    public function fromRawTo(string $key, string $className, mixed $default = null): mixed
    {
        if (Arr::has($this->raw, $key)) {
            /** @var array<string, mixed> $value */
            $value = $this->value($key);

            return $className::fromRaw($value);
        }

        return value($default);
    }

    protected function markAsRetrieved(string $key): void
    {
        if (! in_array($key, $this->retrieved, true)) {
            $this->retrieved[] = $key;
        }
    }
}
