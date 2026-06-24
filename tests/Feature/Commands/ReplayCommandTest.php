<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Testing\FakeResult;

function storeNotification(string $provider, callable $resultFactory, ?Carbon $createdAt = null): PurchaseNotification
{
    $result = $resultFactory();

    return PurchaseNotification::query()->create([
        'provider' => $provider,
        'type' => $result->type()->value,
        'signature_verified' => true,
        'payload' => NotificationResultFactory::snapshot($result),
        'processed_at' => null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('replays all stored notifications through the pipeline', function (): void {
    storeNotification('stripe', fn () => FakeResult::purchase('stripe', 'pi_replay'));
    storeNotification('apple', fn () => FakeResult::subscription('apple', 'sub_replay'));

    $this->artisan('purchases:replay')
        ->expectsOutputToContain('Replayed 2 notification(s).')
        ->assertSuccessful();

    expect(Purchase::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(PurchaseNotification::query()->whereNull('processed_at')->count())->toBe(0);
});

it('replays a single notification by id', function (): void {
    $first = storeNotification('stripe', fn () => FakeResult::purchase('stripe', 'pi_one'));
    storeNotification('stripe', fn () => FakeResult::purchase('stripe', 'pi_two'));

    $this->artisan('purchases:replay', ['id' => $first->getKey()])
        ->expectsOutputToContain('Replayed 1 notification(s).')
        ->assertSuccessful();

    expect(Purchase::query()->count())->toBe(1);
});

it('filters by provider and since date', function (): void {
    storeNotification('stripe', fn () => FakeResult::purchase('stripe', 'pi_a'), Carbon::parse('2026-01-01'));
    storeNotification('apple', fn () => FakeResult::purchase('apple', 'pi_b'), Carbon::parse('2026-06-01'));

    $this->artisan('purchases:replay', ['--provider' => 'apple', '--since' => '2026-05-01'])
        ->expectsOutputToContain('Replayed 1 notification(s).')
        ->assertSuccessful();

    expect(Purchase::query()->where('provider', 'apple')->count())->toBe(1)
        ->and(Purchase::query()->count())->toBe(1);
});

it('warns when no notifications match', function (): void {
    $this->artisan('purchases:replay', ['--provider' => 'nope'])
        ->expectsOutputToContain('No notifications matched the given filters.')
        ->assertSuccessful();
});

it('skips notifications it cannot rebuild', function (): void {
    PurchaseNotification::query()->create([
        'provider' => 'stripe',
        'type' => 'purchase',
        'signature_verified' => true,
        'payload' => ['raw' => ['foo' => 'bar']],
        'processed_at' => null,
    ]);

    $this->artisan('purchases:replay')
        ->expectsOutputToContain('could not rebuild result')
        ->assertSuccessful();

    expect(Purchase::query()->count())->toBe(0);
});
