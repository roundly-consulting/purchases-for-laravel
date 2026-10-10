<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Testing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Testing\Fakes\EventFake;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/**
 * A drop-in spy for the Purchases manager, installed by `Purchases::fake()`. It is a
 * subtype of PurchasesManager, so an injected manager receives it too.
 *
 * It records every `handle()`, `sync()` and `replay()` — through the facade, an injected
 * manager, the webhook controller or `purchases:replay` alike — and still runs the real
 * recording pipeline, so rows are written and lifecycle events fire (it listens for
 * SubscriptionStarted, for `assertSubscriptionStarted()`). Results pushed with
 * `push()` skip signature verification; a faked `handle()` also skips the audit log and
 * the queue and records synchronously.
 */
final class PurchasesFake extends PurchasesManager
{
    /** @var list<ProviderResult> */
    private array $handled = [];

    /** @var list<ProviderResult> */
    private array $synced = [];

    /** @var list<array{notification: PurchaseNotification, result: ProviderResult}> */
    private array $replayed = [];

    /** @var array<string, list<ProviderResult>> */
    private array $queued = [];

    /** @var list<SubscriptionStarted> */
    private array $started = [];

    public function __construct(Container $container, Resolver $resolver)
    {
        parent::__construct($container, $resolver);

        // What counts as a start is the real pipeline's call: the fake only listens for it.
        $this->events()->listen(SubscriptionStarted::class, function (SubscriptionStarted $event): void {
            $this->started[] = $event;
        });
    }

    /**
     * Queue a result so the next handle()/result() call for this provider returns
     * it instead of performing real verification.
     */
    public function push(string $provider, ProviderResult $result): self
    {
        $this->queued[$provider][] = $result;

        return $this;
    }

    public function result(string $id, Request $request): ProviderResult
    {
        if (! empty($this->queued[$id])) {
            return array_shift($this->queued[$id]);
        }

        return parent::result($id, $request);
    }

    public function handle(string $id, Request $request): Model
    {
        $result = $this->result($id, $request);

        $this->handled[] = $result;

        return $this->container->make(RecordProviderResultAction::class)->execute($result)
            ?? NotificationResultFactory::transient($result);
    }

    public function sync(ProviderResult $result): ?Model
    {
        $this->synced[] = $result;

        return parent::sync($result);
    }

    public function replay(PurchaseNotification|int $notification): ?Model
    {
        if (is_int($notification)) {
            $notification = PurchaseNotificationModel::query()->findOrFail($notification);
        }

        $model = parent::replay($notification);

        // The parent only returns once the snapshot rebuilt, so this cannot be null.
        $result = NotificationResultFactory::fromNotification($notification);

        if ($result !== null) {
            $this->replayed[] = ['notification' => $notification, 'result' => $result];
        }

        return $model;
    }

    /** @return Collection<int, ProviderResult> */
    public function handledResults(): Collection
    {
        return collect($this->handled);
    }

    /** @return Collection<int, ProviderResult> */
    public function syncedResults(): Collection
    {
        return collect($this->synced);
    }

    public function assertNothingHandled(): void
    {
        Assert::assertCount(0, $this->handled, 'Expected no provider notifications to be handled.');
    }

    public function assertHandled(string $provider): void
    {
        Assert::assertTrue(
            $this->handledResults()->contains(fn (ProviderResult $r): bool => $r->provider() === $provider),
            "Expected a [{$provider}] notification to be handled.",
        );
    }

    public function assertHandledCount(int $count): void
    {
        Assert::assertCount($count, $this->handled, "Expected {$count} notification(s) to be handled.");
    }

    public function assertSynced(?string $provider = null): void
    {
        Assert::assertTrue(
            $this->syncedResults()->contains(fn (ProviderResult $r): bool => $provider === null || $r->provider() === $provider),
            'Expected a result to be synced'.($provider !== null ? " for [{$provider}]" : '').'.',
        );
    }

    public function assertNothingSynced(): void
    {
        Assert::assertCount(0, $this->synced, 'Expected no results to be synced.');
    }

    /**
     * With no argument, that anything was replayed; otherwise that this notification (a
     * model or its id) was.
     */
    public function assertReplayed(PurchaseNotification|int|null $notification = null): void
    {
        $key = $notification instanceof PurchaseNotification ? self::key($notification) : $notification;

        Assert::assertTrue(
            collect($this->replayed)->contains(
                fn (array $replay): bool => $key === null || self::key($replay['notification']) === (string) $key,
            ),
            $key === null ? 'Expected a notification to be replayed.' : "Expected notification #{$key} to be replayed.",
        );
    }

    public function assertNothingReplayed(): void
    {
        Assert::assertCount(0, $this->replayed, 'Expected no notifications to be replayed.');
    }

    /**
     * A purchase arrived through handle(), sync() or replay().
     */
    public function assertPurchaseRecorded(?string $provider = null): void
    {
        $this->assertRecordedType(ResultType::Purchase, $provider, Purchase::class);
    }

    /**
     * The recording pipeline fired SubscriptionStarted — a subscription was created active,
     * or first activated — whether events are faked or not.
     */
    public function assertSubscriptionStarted(?string $provider = null): void
    {
        $matched = collect($this->startedEvents())->contains(
            fn (SubscriptionStarted $event): bool => $provider === null || $event->result->provider() === $provider,
        );

        Assert::assertTrue(
            $matched,
            'Expected SubscriptionStarted to fire'.($provider !== null ? " for [{$provider}]" : '').'.',
        );
    }

    /**
     * A subscription result arrived through handle(), sync() or replay() — whatever it did
     * to the subscription.
     */
    public function assertSubscriptionRecorded(?string $provider = null): void
    {
        $this->assertRecordedType(ResultType::Subscription, $provider, Subscription::class);
    }

    /**
     * A refund or chargeback arrived through handle(), sync() or replay().
     */
    public function assertRefundRecorded(?string $provider = null): void
    {
        $this->assertRecordedType(ResultType::Refund, $provider, PurchaseRefund::class);
    }

    /**
     * The SubscriptionStarted events heard so far. Under `Event::fake()` a faked event never
     * reaches a listener — the event fake keeps it instead, so it is read from there too.
     *
     * @return list<SubscriptionStarted>
     */
    private function startedEvents(): array
    {
        $started = $this->started;
        $events = $this->events();

        if ($events instanceof EventFake) {
            foreach ($events->dispatched(SubscriptionStarted::class) as $arguments) {
                if (is_array($arguments) && ($arguments[0] ?? null) instanceof SubscriptionStarted) {
                    $started[] = $arguments[0];
                }
            }
        }

        return $started;
    }

    /**
     * The bound event dispatcher — the real one, or whatever `Event::fake()` swapped in.
     */
    private function events(): Dispatcher
    {
        return $this->container->make(Dispatcher::class);
    }

    private static function key(PurchaseNotification $notification): string
    {
        $key = $notification->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    private function assertRecordedType(ResultType $type, ?string $provider, string $label): void
    {
        $seen = [...$this->handled, ...$this->synced, ...array_column($this->replayed, 'result')];

        $matched = collect($seen)->contains(
            fn (ProviderResult $r): bool => $r->type() === $type
                && ($provider === null || $r->provider() === $provider),
        );

        Assert::assertTrue(
            $matched,
            "Expected a {$label} to be recorded".($provider !== null ? " for [{$provider}]" : '').'.',
        );
    }
}
