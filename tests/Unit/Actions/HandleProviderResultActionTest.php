<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Purchases\Actions\HandleProviderResultAction;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Testing\FakeResult;

it('records a verified result now and marks the audit row processed', function (): void {
    $model = app(HandleProviderResultAction::class)->execute(FakeResult::purchase('stripe', 'pi_handle'));

    expect($model)->toBeInstanceOf(Purchase::class)
        ->and(PurchaseNotification::query()->sole()->processed_at)->not->toBeNull();
});

it('queues the result and returns the pending audit row when queueing is on', function (): void {
    Queue::fake();
    config()->set('purchases.queue.enabled', true);

    $model = app(HandleProviderResultAction::class)->execute(FakeResult::purchase('stripe', 'pi_later'));

    expect($model)->toBeInstanceOf(PurchaseNotification::class)
        ->and($model->exists)->toBeTrue()
        ->and($model->getAttribute('processed_at'))->toBeNull()
        ->and(Purchase::query()->count())->toBe(0);

    Queue::assertPushed(ProcessProviderNotification::class, fn (ProcessProviderNotification $job): bool => $job->notificationId === $model->getKey());
});
