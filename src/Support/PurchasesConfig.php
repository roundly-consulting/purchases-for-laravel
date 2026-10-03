<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\PackageToolkit\Support\ConfigValidator;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Providers\Provider;

/**
 * Strict reads of the non-boolean `purchases.*` settings. Provider classes resolve their
 * `purchases.settings.<provider>` section once, so these helpers validate a value they are
 * HANDED and name its full key on failure.
 *
 * A value that is not set — absent, null or blank (`''` or whitespace, a host's `KEY=`) — takes
 * its default; a present value of the wrong shape — `'five'` for the Stripe tolerance, an array
 * for a URL or a queue name — throws {@see InvalidConfigurationException}. A typo is never cast
 * to 0 (which would switch the Stripe replay window off) or swapped for the default.
 *
 * @internal
 */
final class PurchasesConfig
{
    public static function integer(mixed $value, string $key, int $default, ?int $min = null, ?int $max = null): int
    {
        return self::validator($key, $value)->integer($key, $default, $min, $max);
    }

    /** `$default` when not set (absent, null or blank); a non-string throws. */
    public static function string(mixed $value, string $key, string $default): string
    {
        return self::blank($value) ? $default : self::validator($key, $value)->requireString($key);
    }

    /** Null when not set (absent, null or blank); a non-string throws. */
    public static function optionalString(mixed $value, string $key): ?string
    {
        return self::blank($value) ? null : self::validator($key, $value)->requireString($key);
    }

    /**
     * An optional credential: null when not set (absent, null or blank — an unset `KEY=` env
     * line), a string otherwise. Any other type throws — it must never read as "not
     * configured" and quietly skip the check it feeds.
     */
    public static function credential(mixed $value, string $key): ?string
    {
        if (self::blank($value)) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidConfigurationException("[{$key}] must be a string, ".get_debug_type($value).' given.');
        }

        return $value;
    }

    /**
     * A list of non-blank strings: `$default` when not set (absent, null or blank).
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    public static function strings(mixed $value, string $key, array $default): array
    {
        if (self::blank($value)) {
            return $default;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidConfigurationException("[{$key}] must be a list of strings, ".get_debug_type($value).' given.');
        }

        foreach ($value as $entry) {
            if (! is_string($entry) || trim($entry) === '') {
                throw new InvalidConfigurationException("[{$key}] must be a list of non-empty strings, an entry of type ".get_debug_type($entry).' given.');
            }
        }

        return $value;
    }

    /**
     * The registered provider classes (`purchases.providers`): each must implement
     * {@see Provider}.
     *
     * @return list<class-string<Provider>>
     */
    public static function providers(): array
    {
        $providers = self::strings(config('purchases.providers'), 'purchases.providers', []);

        foreach ($providers as $provider) {
            if (! is_a($provider, Provider::class, true)) {
                throw new InvalidConfigurationException('[purchases.providers] must list classes implementing '.Provider::class.", [{$provider}] given.");
            }
        }

        /** @var list<class-string<Provider>> $providers */
        return $providers;
    }

    /** Not set: absent, null or a blank string (`''` or whitespace — a host's `KEY=`). */
    public static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function validator(string $key, mixed $value): ConfigValidator
    {
        return Config::for([$key => $value], InvalidConfigurationException::class);
    }
}
