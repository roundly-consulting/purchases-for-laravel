<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Moves a provider date into the application's timezone before it is stored.
 *
 * Every store reports its dates in UTC. Eloquent writes a date as the wall-clock time of the
 * instance it is handed and reads the column back in the default timezone, so a UTC instance
 * stored unconverted on a non-UTC host comes back shifted by the offset. Converted first, it
 * keeps its instant.
 *
 * @internal building block of the recording DTOs and EventOrder.
 */
final class AppTime
{
    public static function of(?CarbonInterface $at): ?CarbonImmutable
    {
        return $at === null
            ? null
            : CarbonImmutable::instance($at)->setTimezone(date_default_timezone_get());
    }
}
