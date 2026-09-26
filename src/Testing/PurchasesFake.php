<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Testing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Purchases\Actions\RecordProviderNotificationAction;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\Purchases;

/**
 * A drop-in test double for the Purchases manager. It still records and dispatches
 * events through the real action pipeline, but lets tests feed pre-built provider
 * results (no signature verification or HTTP), and exposes Bus::fake()-style
 * assertions over what was handled.
 */
final class PurchasesFake extends Purchases
{
    /** @var list<ProviderResult> */
    private array $handled = [];

    /** @var array<string, list<ProviderResult>> */
    private array $queued = [];

    public function __construct(
        Resolver $resolver,
        SyncProviderResultAction $sync = new SyncProviderResultAction,
        RecordProviderNotificationAction $audit = new RecordProviderNotificationAction,
    ) {
        parent::__construct($resolver, $sync, $audit);
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

        $sync = new SyncProviderResultAction;

        return $sync->execute($result) ?? $this->placeholderNotification($result);
    }

    /** @return Collection<int, ProviderResult> */
    public function handledResults(): Collection
    {
        return collect($this->handled);
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

    public function assertPurchaseRecorded(?string $provider = null): void
    {
        $this->assertRecordedType(ResultType::Purchase, $provider, Purchase::class);
    }

    public function assertSubscriptionStarted(?string $provider = null): void
    {
        $this->assertRecordedType(ResultType::Subscription, $provider, Subscription::class);
    }

    public function assertRefundRecorded(?string $provider = null): void
    {
        $this->assertRecordedType(ResultType::Refund, $provider, PurchaseRefund::class);
    }

    private function assertRecordedType(ResultType $type, ?string $provider, string $label): void
    {
        $matched = $this->handledResults()->contains(
            fn (ProviderResult $r): bool => $r->type() === $type
                && ($provider === null || $r->provider() === $provider),
        );

        Assert::assertTrue(
            $matched,
            "Expected a {$label} to be recorded".($provider !== null ? " for [{$provider}]" : '').'.',
        );
    }
}
