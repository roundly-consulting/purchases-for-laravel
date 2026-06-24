<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Actions\RecordSubscriptionAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\ValueObjects\Money;

it('creates a subscription with lifecycle dates', function (): void {
    $activeFrom = Carbon::parse('2026-01-01');
    $endsAt = Carbon::parse('2026-02-01');

    $subscription = (new RecordSubscriptionAction)->execute(new RecordSubscriptionData(
        provider: 'google',
        providerId: 'GPA.1',
        status: Status::Completed,
        name: 'pro.monthly',
        price: new Money(999, 'USD'),
        activeFrom: $activeFrom,
        endsAt: $endsAt,
    ));

    expect($subscription->name)->toBe('pro.monthly')
        ->and($subscription->active_from?->toDateString())->toBe('2026-01-01')
        ->and($subscription->ends_at?->toDateString())->toBe('2026-02-01')
        ->and($subscription->price?->amount)->toBe(999);
});

it('updates rather than duplicates a subscription', function (): void {
    $action = new RecordSubscriptionAction;

    $action->execute(new RecordSubscriptionData('google', 'GPA.1', Status::Pending, 'pro'));
    $action->execute(new RecordSubscriptionData('google', 'GPA.1', Status::Completed, 'pro'));

    expect(Subscription::query()->count())->toBe(1);
});

it('syncs subscription items', function (): void {
    $subscription = (new RecordSubscriptionAction)->execute(new RecordSubscriptionData(
        provider: 'google',
        providerId: 'GPA.1',
        status: Status::Completed,
        name: 'pro',
        items: [new ResultItem(name: 'Pro plan', providerId: 'pro.monthly', price: new Money(999, 'USD'))],
    ));

    expect($subscription->items()->count())->toBe(1)
        ->and($subscription->items()->first()?->name)->toBe('Pro plan');
});
