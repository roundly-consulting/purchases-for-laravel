<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Testing\FakeResult;

it('round-trips a result through a snapshot', function (): void {
    $original = FakeResult::subscription('stripe', 'sub_rt');

    $notification = PurchaseNotification::factory()->create([
        'payload' => NotificationResultFactory::snapshot($original),
    ]);

    $rebuilt = NotificationResultFactory::fromNotification($notification);

    expect($rebuilt)->not->toBeNull()
        ->and($rebuilt?->provider())->toBe('stripe')
        ->and($rebuilt?->type())->toBe(ResultType::Subscription)
        ->and($rebuilt?->providerId())->toBe('sub_rt')
        ->and($rebuilt?->status())->toBe(Status::Completed)
        ->and($rebuilt?->price()?->amount)->toBe(1999)
        ->and($rebuilt?->endsAt())->toBeInstanceOf(Carbon::class);
});

it('round-trips a refund snapshot', function (): void {
    $original = FakeResult::refund('stripe', 're_rt', chargeback: true);

    $notification = PurchaseNotification::factory()->create([
        'payload' => NotificationResultFactory::snapshot($original),
    ]);

    $rebuilt = NotificationResultFactory::fromNotification($notification);

    expect($rebuilt?->type())->toBe(ResultType::Refund)
        ->and($rebuilt?->isChargeback())->toBeTrue()
        ->and($rebuilt?->refundReason())->toBe('fraudulent');
});

it('returns null when the snapshot is incomplete', function (): void {
    $notification = PurchaseNotification::factory()->create([
        'payload' => ['raw' => ['foo' => 'bar']],
    ]);

    expect(NotificationResultFactory::fromNotification($notification))->toBeNull();
});
