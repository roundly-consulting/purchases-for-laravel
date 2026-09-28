<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Actions\ReplayProviderNotificationAction;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Testing\FakeResult;

it('rebuilds the result from the snapshot, records it and marks the row processed', function (): void {
    $notification = auditedNotification(FakeResult::purchase('stripe', 'pi_action'));

    $model = app(ReplayProviderNotificationAction::class)->execute($notification);

    expect($model)->toBeInstanceOf(Purchase::class)
        ->and($model?->getAttribute('provider_id'))->toBe('pi_action')
        ->and($notification->refresh()->processed_at)->not->toBeNull()
        ->and(PurchaseNotification::query()->count())->toBe(1);
});

it('is idempotent: a second replay records nothing new and fires nothing', function (): void {
    $notification = auditedNotification(FakeResult::purchase('stripe', 'pi_twice'));

    app(ReplayProviderNotificationAction::class)->execute($notification);

    Event::fake([PurchaseCompleted::class]);
    app(ReplayProviderNotificationAction::class)->execute((int) $notification->getKey());

    expect(Purchase::query()->count())->toBe(1);
    Event::assertNotDispatched(PurchaseCompleted::class);
});

it('fails for an id with no stored notification', function (): void {
    app(ReplayProviderNotificationAction::class)->execute(987654);
})->throws(ModelNotFoundException::class);
