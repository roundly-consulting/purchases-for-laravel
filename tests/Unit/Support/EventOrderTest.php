<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\EventOrder;

function at(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time);
}

it('calls an event stale only when it is provably older than the one applied', function (?string $applied, ?string $incoming, bool $stale): void {
    expect(EventOrder::isStale($applied === null ? null : at($applied), $incoming === null ? null : at($incoming)))->toBe($stale);
})->with([
    'older' => ['2026-01-01 10:00:05', '2026-01-01 10:00:04', true],
    'same second' => ['2026-01-01 10:00:05', '2026-01-01 10:00:05', false],
    'newer' => ['2026-01-01 10:00:05', '2026-01-01 10:00:06', false],
    // Whole seconds: a millisecond earlier inside the same second is not provably older.
    'earlier within the same second' => ['2026-01-01 10:00:05.900', '2026-01-01 10:00:05.100', false],
    'nothing applied yet' => [null, '2026-01-01 10:00:05', false],
    'the event does not say' => ['2026-01-01 10:00:05', null, false],
]);

it('lets a refunded row move only on a provably newer event', function (?string $applied, ?string $incoming, Status $next, bool $permitted): void {
    expect(EventOrder::permits($applied === null ? null : at($applied), Status::Refunded, $incoming === null ? null : at($incoming), $next))->toBe($permitted);
})->with([
    'reinstated by a newer event' => ['2026-01-01 10:00:05', '2026-01-01 10:00:06', Status::Completed, true],
    'not by one in the same second' => ['2026-01-01 10:00:05', '2026-01-01 10:00:05', Status::Completed, false],
    'not by an older one' => ['2026-01-01 10:00:05', '2026-01-01 10:00:04', Status::Completed, false],
    'not by one that cannot say when' => ['2026-01-01 10:00:05', null, Status::Completed, false],
    'not when the refund time is unknown' => [null, '2026-01-01 10:00:06', Status::Completed, false],
    'refunded again in the same second' => ['2026-01-01 10:00:05', '2026-01-01 10:00:05', Status::Refunded, true],
]);

it('lets any other row move unless the event is stale', function (): void {
    expect(EventOrder::permits(at('2026-01-01 10:00:05'), Status::Completed, null, Status::Failed))->toBeTrue()
        ->and(EventOrder::permits(null, Status::Completed, at('2026-01-01 10:00:05'), Status::Failed))->toBeTrue()
        ->and(EventOrder::permits(null, null, null, Status::Completed))->toBeTrue()
        ->and(EventOrder::permits(at('2026-01-01 10:00:05'), Status::Completed, at('2026-01-01 10:00:04'), Status::Failed))->toBeFalse();
});

it('keeps the later of the two times, at whole seconds', function (): void {
    expect(EventOrder::latest(null, null))->toBeNull()
        ->and(EventOrder::latest(at('2026-01-01 10:00:05.700'), null)?->format('H:i:s.v'))->toBe('10:00:05.000')
        ->and(EventOrder::latest(null, at('2026-01-01 10:00:06'))?->format('H:i:s'))->toBe('10:00:06')
        ->and(EventOrder::latest(at('2026-01-01 10:00:05'), at('2026-01-01 10:00:04'))?->format('H:i:s'))->toBe('10:00:05')
        ->and(EventOrder::latest(at('2026-01-01 10:00:05'), at('2026-01-01 10:00:07'))?->format('H:i:s'))->toBe('10:00:07');
});

it('stores the time in the application timezone', function (): void {
    $utc = CarbonImmutable::parse('2026-01-01 10:00:05', 'UTC');

    expect(EventOrder::latest(null, $utc->setTimezone('Asia/Tokyo'))?->getTimezone()->getName())->toBe(date_default_timezone_get())
        ->and(EventOrder::latest(null, $utc->setTimezone('Asia/Tokyo'))?->equalTo($utc))->toBeTrue();
});
