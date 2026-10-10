<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\Enum\Status;

/**
 * Orders provider events on one stored purchase, subscription or refund.
 *
 * Every store delivers at least once and in no particular order, and the audit log can be
 * replayed at any time — so an event is applied only when it is not older than the last
 * one applied to its row (`last_event_at`), and a refunded row moves again only on an event
 * provably newer than its refund (Apple's REFUND_REVERSED, a won dispute). A result that
 * cannot say when it happened is applied as before — except that it never un-refunds.
 *
 * Times compare at whole seconds, the precision every database keeps in a timestamp column,
 * and are stored in the application's timezone, the one Eloquent reads them back in.
 *
 * @internal building block of the recording actions.
 */
final class EventOrder
{
    /**
     * Whether an event is older than the last one applied — a redelivery, a replay or an
     * out-of-order delivery of something already superseded.
     */
    public static function isStale(?CarbonInterface $applied, ?CarbonInterface $incoming): bool
    {
        $applied = self::second($applied);
        $incoming = self::second($incoming);

        return $applied !== null && $incoming !== null && $incoming->lessThan($applied);
    }

    /**
     * Whether an event may move a row from its current status to the next one.
     */
    public static function permits(?CarbonInterface $applied, ?Status $current, ?CarbonInterface $incoming, Status $next): bool
    {
        if (self::isStale($applied, $incoming)) {
            return false;
        }

        if ($current !== Status::Refunded || $next === Status::Refunded) {
            return true;
        }

        // Refunded is final unless something provably newer reverses it.
        $applied = self::second($applied);
        $incoming = self::second($incoming);

        return $applied !== null && $incoming !== null && $incoming->greaterThan($applied);
    }

    /**
     * The `last_event_at` to store once an event is applied: the later of the two.
     */
    public static function latest(?CarbonInterface $applied, ?CarbonInterface $incoming): ?CarbonImmutable
    {
        $applied = self::second($applied);
        $incoming = self::second($incoming);

        if ($applied === null || $incoming === null) {
            return $applied ?? $incoming;
        }

        return $incoming->greaterThan($applied) ? $incoming : $applied;
    }

    private static function second(?CarbonInterface $at): ?CarbonImmutable
    {
        return AppTime::of($at)?->startOfSecond();
    }
}
