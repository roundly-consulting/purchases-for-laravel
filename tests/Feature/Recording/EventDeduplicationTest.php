<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Events\SubscriptionTrialWillEnd;
use RoundlyConsulting\Purchases\Exceptions\EventInProgressException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Tests\Support\Racer;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_dedup',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    config()->set('purchases.audit.deduplicate', true);
    Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * One signed delivery of the Stripe event `evt_dedup` — every call is the same event again.
 */
function dedupDelivery(string $type = 'customer.subscription.created', string $status = 'active'): Request
{
    return stripeSignedRequest([
        'id' => 'evt_dedup',
        'type' => $type,
        'created' => Carbon::now()->subMinute()->getTimestamp(),
        'data' => ['object' => stripeBillingSubscription(['status' => $status])],
    ]);
}

/**
 * The audit row of `evt_dedup`, as an earlier delivery left it.
 *
 * @param  array<string, mixed>  $attributes
 */
function dedupClaimed(array $attributes): PurchaseNotification
{
    return PurchaseNotification::factory()->create($attributes + [
        'provider' => 'stripe',
        'type' => 'subscription',
        'signature_verified' => true,
        'event_id' => 'evt_dedup',
    ]);
}

it('processes an event once and answers a repeat with its first audit row', function (): void {
    Event::fake([SubscriptionStarted::class]);

    $first = Purchases::handle('stripe', dedupDelivery());
    $repeat = Purchases::handle('stripe', dedupDelivery());

    $notification = PurchaseNotification::query()->sole();

    expect($first)->toBeInstanceOf(Subscription::class)
        ->and($repeat)->toBeInstanceOf(PurchaseNotification::class)
        ->and($repeat->is($notification))->toBeTrue()
        ->and($notification->event_id)->toBe('evt_dedup')
        ->and($notification->eventId())->toBe('evt_dedup')
        ->and($notification->processed_at)->not->toBeNull()
        ->and($notification->claimed_at)->not->toBeNull()
        ->and(Subscription::query()->count())->toBe(1);
    Event::assertDispatchedTimes(SubscriptionStarted::class, 1);
});

it('fires a signal once when its event is delivered twice', function (): void {
    Event::fake([SubscriptionTrialWillEnd::class]);

    Purchases::handle('stripe', dedupDelivery('customer.subscription.trial_will_end', 'trialing'));
    Purchases::handle('stripe', dedupDelivery('customer.subscription.trial_will_end', 'trialing'));

    Event::assertDispatchedTimes(SubscriptionTrialWillEnd::class, 1);
});

it('processes every delivery, and writes no event id column, when deduplication is off', function (): void {
    config()->set('purchases.audit.deduplicate', false);

    Purchases::handle('stripe', dedupDelivery());
    Purchases::handle('stripe', dedupDelivery());

    $rows = PurchaseNotification::query()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('event_id')->filter()->all())->toBe([])
        // The snapshot still names the event.
        ->and($rows->map->eventId()->unique()->all())->toBe(['evt_dedup']);
});

it('refuses a redelivery while another delivery is still on the event', function (): void {
    dedupClaimed(['claimed_at' => Carbon::now()->subSeconds(30), 'processed_at' => null]);

    expect(fn () => Purchases::handle('stripe', dedupDelivery()))
        ->toThrow(EventInProgressException::class, 'evt_dedup');

    expect(Subscription::query()->count())->toBe(0);
});

it('takes over an event whose claim timed out', function (): void {
    config()->set('purchases.audit.claim_timeout', 60);
    $stale = dedupClaimed(['claimed_at' => Carbon::now()->subSeconds(61), 'processed_at' => null]);

    $recorded = Purchases::handle('stripe', dedupDelivery());

    expect($recorded)->toBeInstanceOf(Subscription::class)
        ->and(PurchaseNotification::query()->sole()->is($stale))->toBeTrue()
        ->and($stale->refresh()->processed_at)->not->toBeNull()
        ->and($stale->claimed_at?->equalTo(Carbon::now()))->toBeTrue();
});

it('releases the claim of a delivery that failed, so the next one takes over at once', function (): void {
    $failing = true;
    Subscription::creating(function () use (&$failing): void {
        if ($failing) {
            throw new RuntimeException('database hiccup');
        }
    });

    expect(fn () => Purchases::handle('stripe', dedupDelivery()))->toThrow(RuntimeException::class, 'database hiccup');

    $notification = PurchaseNotification::query()->sole();
    expect($notification->claimed_at)->toBeNull()
        ->and($notification->processed_at)->toBeNull();

    $failing = false;

    expect(Purchases::handle('stripe', dedupDelivery()))->toBeInstanceOf(Subscription::class)
        ->and(PurchaseNotification::query()->sole()->processed_at)->not->toBeNull();
});

it('treats an event whose audit row the host deleted as processed', function (): void {
    dedupClaimed(['claimed_at' => null, 'processed_at' => null])->delete();

    $answer = Purchases::handle('stripe', dedupDelivery());

    expect($answer)->toBeInstanceOf(PurchaseNotification::class)
        ->and($answer->trashed())->toBeTrue()
        ->and(Subscription::query()->count())->toBe(0);
});

it('skips a repeat when the delivery it lost the takeover to has finished', function (): void {
    dedupClaimed(['claimed_at' => Carbon::now()->subSeconds(5), 'processed_at' => null]);

    // The winner finishes between this delivery's read and its takeover attempt.
    PurchaseNotification::retrieved(function (PurchaseNotification $notification): void {
        PurchaseNotification::query()->whereKey($notification->getKey())->whereNull('processed_at')->update(['processed_at' => Carbon::now()]);
    });

    $answer = Purchases::handle('stripe', dedupDelivery());

    expect($answer)->toBeInstanceOf(PurchaseNotification::class)
        ->and(Subscription::query()->count())->toBe(0);
});

it('queues the first delivery, holds a repeat off until the job is done, then skips it', function (): void {
    config()->set('purchases.queue.enabled', true);
    Queue::fake();

    $first = Purchases::handle('stripe', dedupDelivery());

    expect($first)->toBeInstanceOf(PurchaseNotification::class)
        ->and(fn () => Purchases::handle('stripe', dedupDelivery()))->toThrow(EventInProgressException::class);

    Queue::assertPushed(ProcessProviderNotification::class, 1);
    $job = Queue::pushed(ProcessProviderNotification::class)->sole();
    expect($job->notificationId)->toBe($first->getKey());
    app()->call([$job, 'handle']);

    $repeat = Purchases::handle('stripe', dedupDelivery());

    expect($repeat->is($first))->toBeTrue()
        ->and(Subscription::query()->count())->toBe(1);
    Queue::assertPushed(ProcessProviderNotification::class, 1);
});

it('needs the audit log to deduplicate', function (): void {
    config()->set('purchases.audit.enabled', false);

    expect(fn () => Purchases::handle('stripe', dedupDelivery()))
        ->toThrow(InvalidConfigurationException::class, 'purchases.audit.deduplicate');
});

it('refuses a claim timeout that is not a positive number of seconds', function (): void {
    config()->set('purchases.audit.claim_timeout', 'soon');
    dedupClaimed(['claimed_at' => Carbon::now(), 'processed_at' => null]);

    expect(fn () => Purchases::handle('stripe', dedupDelivery()))
        ->toThrow(InvalidConfigurationException::class, 'purchases.audit.claim_timeout');
});

it('leaves a result that names no event to the plain pipeline', function (): void {
    config()->set('purchases.audit.enabled', false);

    $handled = Purchases::handle('stripe', stripeSignedRequest([
        'type' => 'customer.subscription.created',
        'created' => Carbon::now()->subMinute()->getTimestamp(),
        'data' => ['object' => stripeBillingSubscription()],
    ]));

    expect($handled)->toBeInstanceOf(Subscription::class);
});

it('stores no event id for a result synced by the host', function (): void {
    $result = new GenericResult('stripe', ResultType::Subscription, 'sub_host', Status::Completed, name: 'pro', eventId: 'evt_host');

    expect(Purchases::sync($result))->toBeInstanceOf(Subscription::class)
        ->and(PurchaseNotification::query()->sole()->event_id)->toBeNull();
});

it('records an event once when two deliveries race on a real engine', function (): void {
    // Both deliveries are built before the fork: each child sends its own copy.
    $deliveries = [dedupDelivery(), dedupDelivery()];

    $outcomes = Racer::race(array_map(static fn (Request $delivery): Closure => static function () use ($delivery): string {
        try {
            $answer = Purchases::handle('stripe', $delivery);
        } catch (EventInProgressException) {
            return 'in-progress';
        }

        return $answer instanceof Subscription ? 'processed' : 'repeat';
    }, $deliveries));

    sort($outcomes);

    // Exactly one delivery processed it; the other found it done, or still being done.
    expect($outcomes[1])->toBe('processed')
        ->and($outcomes[0])->toBeIn(['in-progress', 'repeat'])
        ->and(PurchaseNotification::query()->where('event_id', 'evt_dedup')->count())->toBe(1)
        ->and(PurchaseNotification::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
})->skip(fn (): bool => ! Racer::available(), 'needs pgsql or mysql and pcntl');
