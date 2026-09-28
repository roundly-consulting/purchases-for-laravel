<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Purchases\Actions\RecordProviderNotificationAction;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Provider;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Testing\FakeResult;

/**
 * A provider stub that returns a pre-built result for any request.
 */
function fakeProvider(GenericResult $result): Provider
{
    return new class($result) implements Provider
    {
        public function __construct(private readonly GenericResult $result) {}

        public function id(): string
        {
            return $this->result->provider();
        }

        public function notification(Request $request): mixed
        {
            return $this->result;
        }

        public function callback(Request $request): mixed
        {
            return $this->result;
        }

        public function result(Request $request): GenericResult
        {
            return $this->result;
        }
    };
}

/**
 * Builds the manager over the REAL Resolver rather than a mock of it.
 *
 * The stub provider is registered the way a host registers one: listed in
 * `purchases.providers` and resolvable from the container. That is the documented seam
 * (`extend BaseProvider`, list the class), so this exercises Resolver's actual
 * config-read-and-key-by-id behaviour instead of asserting against a double that agrees
 * with whatever we tell it. Resolver reads the config in its constructor, so the key must
 * be set before it is built.
 */
function managerFor(GenericResult $result): PurchasesManager
{
    $provider = fakeProvider($result);

    app()->instance($provider::class, $provider);
    config()->set('purchases.providers', [$provider::class]);

    return new PurchasesManager(app(), new Resolver);
}

it('records a raw notification snapshot before reducing it', function (): void {
    $result = FakeResult::purchase('stripe', 'pi_audit');

    $notification = app(RecordProviderNotificationAction::class)->execute($result);

    expect($notification)->toBeInstanceOf(PurchaseNotification::class)
        ->and($notification?->provider)->toBe('stripe')
        ->and($notification?->signature_verified)->toBeTrue()
        ->and($notification?->processed_at)->toBeNull()
        ->and($notification?->payload->get('provider_id'))->toBe('pi_audit');
});

it('skips the audit log when auditing is disabled', function (): void {
    config()->set('purchases.audit.enabled', false);

    $notification = app(RecordProviderNotificationAction::class)->execute(FakeResult::purchase());

    expect($notification)->toBeNull();
});

it('records synchronously and marks the audit row processed', function (): void {
    $manager = managerFor(FakeResult::purchase('stripe', 'pi_sync'));

    $model = $manager->handle('stripe', Request::create('/'));

    expect($model)->toBeInstanceOf(Purchase::class)
        ->and(Purchase::query()->count())->toBe(1)
        ->and(PurchaseNotification::query()->first()?->processed_at)->not->toBeNull();
});

it('queues persistence and returns the audit notification when queueing is enabled', function (): void {
    Queue::fake();
    config()->set('purchases.queue.enabled', true);

    $manager = managerFor(FakeResult::purchase('stripe', 'pi_queued'));

    $model = $manager->handle('stripe', Request::create('/'));

    expect($model)->toBeInstanceOf(PurchaseNotification::class)
        ->and(Purchase::query()->count())->toBe(0);

    Queue::assertPushed(ProcessProviderNotification::class);
});

it('persists the result when the queued job runs', function (): void {
    $notification = PurchaseNotification::factory()->create();

    $job = new ProcessProviderNotification(FakeResult::subscription('stripe', 'sub_q'), $notification->getKey());
    $job->handle(app(RecordProviderResultAction::class));

    expect($notification->refresh()->processed_at)->not->toBeNull();
});

it('returns a transient notification when auditing is off but queueing is on', function (): void {
    Queue::fake();
    config()->set('purchases.audit.enabled', false);
    config()->set('purchases.queue.enabled', true);

    $manager = managerFor(FakeResult::purchase('stripe', 'pi_transient'));

    $model = $manager->handle('stripe', Request::create('/'));

    expect($model)->toBeInstanceOf(PurchaseNotification::class)
        ->and($model->exists)->toBeFalse();

    Queue::assertPushed(ProcessProviderNotification::class);
});

/**
 * An informational result: something happened at the store, but nothing to record.
 */
function informationalResult(ResultType $type = ResultType::Notification): GenericResult
{
    return new GenericResult(provider: 'stripe', type: $type, providerId: 'evt_info', status: Status::Processing);
}

it('records nothing and fires nothing for an informational result', function (ResultType $type): void {
    Event::fake();

    $model = app(RecordProviderResultAction::class)->execute(informationalResult($type));

    expect($model)->toBeNull()
        ->and(Purchase::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(PurchaseRefund::query()->count())->toBe(0);

    Event::assertNothingDispatched();
})->with([ResultType::Notification, ResultType::Unknown]);

it('returns the processed audit notification when handling an informational result', function (): void {
    $model = managerFor(informationalResult())->handle('stripe', Request::create('/'));

    expect($model)->toBeInstanceOf(PurchaseNotification::class)
        ->and($model->exists)->toBeTrue()
        ->and($model->getAttribute('processed_at'))->not->toBeNull()
        ->and(Subscription::query()->count())->toBe(0);
});

it('returns a transient notification for an informational result when auditing is off', function (): void {
    config()->set('purchases.audit.enabled', false);

    $model = managerFor(informationalResult())->handle('stripe', Request::create('/'));

    expect($model)->toBeInstanceOf(PurchaseNotification::class)
        ->and($model->exists)->toBeFalse()
        ->and(Subscription::query()->count())->toBe(0);
});

it('marks a queued informational notification processed without recording anything', function (): void {
    $notification = PurchaseNotification::factory()->create();

    (new ProcessProviderNotification(informationalResult(), $notification->getKey()))->handle(app(RecordProviderResultAction::class));

    expect($notification->refresh()->processed_at)->not->toBeNull()
        ->and(Subscription::query()->count())->toBe(0);
});
