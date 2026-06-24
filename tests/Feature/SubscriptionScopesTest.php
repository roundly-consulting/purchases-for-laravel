<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Subscription;

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-01 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('scopes to active subscriptions', function (): void {
    Subscription::factory()->create(['status' => Status::Completed, 'ends_at' => Carbon::now()->addWeek()]);
    Subscription::factory()->create(['status' => Status::InGracePeriod, 'ends_at' => null]);
    Subscription::factory()->create(['status' => Status::Canceled, 'ends_at' => Carbon::now()->addWeek()]);
    Subscription::factory()->create(['status' => Status::Completed, 'ends_at' => Carbon::now()->subDay()]);

    expect(Subscription::active()->count())->toBe(2);
});

it('scopes to trialing subscriptions', function (): void {
    Subscription::factory()->create(['trial_ends_at' => Carbon::now()->addDays(3)]);
    Subscription::factory()->create(['trial_ends_at' => Carbon::now()->subDay()]);
    Subscription::factory()->create(['trial_ends_at' => null]);

    expect(Subscription::trialing()->count())->toBe(1);
});

it('scopes to expiring subscriptions within a window', function (): void {
    Subscription::factory()->create(['status' => Status::Completed, 'ends_at' => Carbon::now()->addDays(3)]);
    Subscription::factory()->create(['status' => Status::Completed, 'ends_at' => Carbon::now()->addDays(30)]);

    expect(Subscription::active()->expiring(7)->count())->toBe(1)
        ->and(Subscription::expiring(7)->count())->toBe(1);
});

it('scopes to canceled subscriptions', function (): void {
    Subscription::factory()->create(['status' => Status::Canceled]);
    Subscription::factory()->create(['status' => Status::Completed]);

    expect(Subscription::canceled()->count())->toBe(1);
});

it('reports model-level helpers', function (): void {
    $active = Subscription::factory()->create([
        'status' => Status::Completed,
        'trial_ends_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(5),
    ]);

    expect($active->isActive())->toBeTrue()
        ->and($active->onTrial())->toBeTrue()
        ->and($active->isExpiring(7))->toBeTrue()
        ->and($active->isExpiring(2))->toBeFalse()
        ->and($active->daysUntilRenewal())->toBe(5);
});

it('returns null days until renewal without an end date', function (): void {
    $subscription = Subscription::factory()->create(['ends_at' => null]);

    expect($subscription->daysUntilRenewal())->toBeNull()
        ->and($subscription->isExpiring())->toBeFalse();
});

it('is inactive once expired even if completed', function (): void {
    $subscription = Subscription::factory()->create([
        'status' => Status::Completed,
        'ends_at' => Carbon::now()->subDay(),
    ]);

    expect($subscription->isActive())->toBeFalse();
});
