<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

it('relates an owner to its purchases and subscriptions', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    $purchase = Purchase::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    Subscription::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'name' => 'pro',
        'status' => Status::Completed,
    ]);

    expect($user->purchases()->count())->toBe(1)
        ->and($user->purchases()->first()?->is($purchase))->toBeTrue()
        ->and($user->subscriptions()->count())->toBe(1);
});

it('resolves the active subscription, optionally by name', function (): void {
    $user = User::query()->create(['name' => 'Grace']);

    Subscription::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'name' => 'pro',
        'status' => Status::Completed,
        'ends_at' => null,
    ]);

    Subscription::factory()->create([
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
        'name' => 'basic',
        'status' => Status::Canceled,
    ]);

    expect($user->activeSubscription())->not->toBeNull()
        ->and($user->activeSubscription('pro')?->name)->toBe('pro')
        ->and($user->activeSubscription('basic'))->toBeNull()
        ->and($user->subscribedTo('pro'))->toBeTrue()
        ->and($user->subscribedTo('basic'))->toBeFalse();
});
