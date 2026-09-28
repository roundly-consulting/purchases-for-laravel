<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\OwnerPurchases;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Testing\FakeResult;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

it('documents its root, is fakeable and reaches every action', function (): void {
    expect(Purchases::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('syncs a result the host already holds, audited like a webhook', function (): void {
    Event::fake([PurchaseCompleted::class]);

    $model = Purchases::sync(FakeResult::purchase('apple', 'txn_client'));

    $audit = PurchaseNotification::query()->sole();

    expect($model)->toBeInstanceOf(Purchase::class)
        ->and($model?->getAttribute('provider_id'))->toBe('txn_client')
        ->and($audit->payload->get('provider_id'))->toBe('txn_client')
        ->and($audit->processed_at)->not->toBeNull();

    Event::assertDispatched(PurchaseCompleted::class);
});

it('syncs an informational result to null, audited but recording nothing', function (): void {
    $model = Purchases::sync(new GenericResult('apple', ResultType::Notification, 'n-1', Status::Processing));

    expect($model)->toBeNull()
        ->and(Purchase::query()->count())->toBe(0)
        ->and(PurchaseNotification::query()->sole()->processed_at)->not->toBeNull();
});

it('syncs synchronously even when webhook processing is queued', function (): void {
    config()->set('purchases.queue.enabled', true);

    expect(Purchases::sync(FakeResult::subscription('google', 'GPA.sync')))->toBeInstanceOf(Subscription::class);
});

it('replays a stored notification by model and by id, without a new audit row', function (): void {
    $first = auditedNotification(FakeResult::purchase('stripe', 'pi_replay_model'));
    $second = auditedNotification(FakeResult::subscription('apple', 'sub_replay_id'));

    $purchase = Purchases::replay($first);
    $subscription = Purchases::replay((int) $second->getKey());

    expect($purchase)->toBeInstanceOf(Purchase::class)
        ->and($subscription)->toBeInstanceOf(Subscription::class)
        ->and($first->refresh()->processed_at)->not->toBeNull()
        ->and($second->refresh()->processed_at)->not->toBeNull()
        ->and(PurchaseNotification::query()->count())->toBe(2);
});

it('refuses to replay a notification whose signature was never verified', function (): void {
    $notification = auditedNotification(FakeResult::purchase('stripe', 'pi_unverified'));
    $notification->update(['signature_verified' => false]);

    expect(fn () => Purchases::replay($notification))
        ->toThrow(InvalidProviderNotificationException::class, 'never signature-verified');

    expect(Purchase::query()->count())->toBe(0);
});

it('refuses to replay a deleted or transient notification', function (): void {
    $deleted = auditedNotification(FakeResult::purchase('stripe', 'pi_deleted'));
    $deleted->delete();

    expect(fn () => Purchases::replay($deleted))->toThrow(InvalidProviderNotificationException::class, 'is not stored')
        ->and(fn () => Purchases::replay((int) $deleted->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Purchases::replay(NotificationResultFactory::transient(FakeResult::purchase())))
        ->toThrow(InvalidProviderNotificationException::class, 'is not stored');
});

it('refuses to replay a snapshot that no longer rebuilds', function (): void {
    $notification = PurchaseNotification::factory()->create(['payload' => ['raw' => []]]);

    Purchases::replay($notification);
})->throws(InvalidProviderNotificationException::class, 'could not be rebuilt into a result');

it('handles a request through the facade', function (): void {
    Purchases::fake()->push('stripe', FakeResult::purchase('stripe', 'pi_facade'));

    expect(Purchases::handle('stripe', Request::create('/')))->toBeInstanceOf(Purchase::class);
});

it('reads one owner through Purchases::for()', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    ownedBy($user, Subscription::factory()->create(['name' => 'pro', 'status' => Status::Completed, 'ends_at' => null]));
    ownedBy($user, Subscription::factory()->create(['name' => 'basic', 'status' => Status::Canceled]));
    ownedBy($user, Purchase::factory()->create());

    $owner = Purchases::for($user);

    expect($owner)->toBeInstanceOf(OwnerPurchases::class)
        ->and($owner->purchases()->count())->toBe(1)
        ->and($owner->subscriptions()->count())->toBe(2)
        ->and($owner->activeSubscription()?->name)->toBe('pro')
        ->and($owner->activeSubscription('pro')?->name)->toBe('pro')
        ->and($owner->activeSubscription('basic'))->toBeNull()
        ->and($owner->subscribedTo('pro'))->toBeTrue()
        ->and($owner->subscribedTo('basic'))->toBeFalse();
});

it('scopes Purchases::for() to one owner, never another owner or a same-id owner of another type', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $grace = User::query()->create(['name' => 'Grace']);
    $organization = Organization::query()->findOrFail($ada->getKey());

    ownedBy($grace, Subscription::factory()->create(['name' => 'pro', 'status' => Status::Completed, 'ends_at' => null]));
    ownedBy($organization, Subscription::factory()->create(['name' => 'pro', 'status' => Status::Completed, 'ends_at' => null]));
    ownedBy($organization, Purchase::factory()->create());

    expect(Purchases::for($ada)->subscribedTo('pro'))->toBeFalse()
        ->and(Purchases::for($ada)->subscriptions()->count())->toBe(0)
        ->and(Purchases::for($ada)->purchases()->count())->toBe(0)
        ->and(Purchases::for($grace)->subscribedTo('pro'))->toBeTrue()
        ->and(Purchases::for($organization)->subscribedTo('pro'))->toBeTrue()
        ->and(Purchases::for($organization)->purchases()->count())->toBe(1);
});

it('gives an unsaved owner nothing, never the rows no owner is linked to yet', function (): void {
    Subscription::factory()->create(['name' => 'pro', 'status' => Status::Completed, 'ends_at' => null]);
    Purchase::factory()->create();

    $unsaved = new User;

    expect(Purchases::for($unsaved)->subscriptions()->count())->toBe(0)
        ->and(Purchases::for($unsaved)->purchases()->count())->toBe(0)
        ->and(Purchases::for($unsaved)->subscribedTo('pro'))->toBeFalse();
});

it('serves the same API to an injected manager', function (): void {
    Event::fake([SubscriptionStarted::class]);

    $manager = app(PurchasesManager::class);
    $user = User::query()->create(['name' => 'Ada']);

    $subscription = $manager->sync(FakeResult::subscription('apple', 'sub_di'));
    ownedBy($user, $subscription);

    expect($manager)->toBe(Purchases::getFacadeRoot())
        ->and($manager->for($user)->subscribedTo('pro'))->toBeTrue()
        ->and($manager->replay((int) PurchaseNotification::query()->sole()->getKey()))->toBeInstanceOf(Subscription::class);

    Event::assertDispatchedTimes(SubscriptionStarted::class, 1);
});

it('routes the HasPurchases helpers through the manager', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    ownedBy($user, Subscription::factory()->create(['name' => 'pro', 'status' => Status::Completed, 'ends_at' => null]));

    $spy = new class(app(), app(Resolver::class)) extends PurchasesManager
    {
        public int $calls = 0;

        public function for(Model $owner): OwnerPurchases
        {
            $this->calls++;

            return parent::for($owner);
        }
    };

    app()->instance(PurchasesManager::class, $spy);

    expect($user->subscribedTo('pro'))->toBeTrue()
        ->and($user->activeSubscription()?->name)->toBe('pro')
        ->and($spy->calls)->toBe(2);
});
