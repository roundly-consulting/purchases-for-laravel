<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Actions\RecordProviderNotificationAction;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Exceptions\UnknownProviderException;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Providers\Provider;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/**
 * The expressive entry point for the package: resolve providers, decode results,
 * and persist them in a single call.
 */
class Purchases
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly SyncProviderResultAction $sync = new SyncProviderResultAction,
        private readonly RecordProviderNotificationAction $audit = new RecordProviderNotificationAction,
    ) {}

    /**
     * Resolve a provider by id, throwing when it is not configured.
     */
    public function provider(string $id): Provider
    {
        $provider = $this->resolver->resolve($id);

        if ($provider === null) {
            throw UnknownProviderException::because("No provider registered for [{$id}].");
        }

        return $provider;
    }

    public function has(string $id): bool
    {
        return $this->resolver->resolve($id) !== null;
    }

    /**
     * @return Collection<string, Provider>
     */
    public function providers(): Collection
    {
        return $this->resolver->all();
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values($this->resolver->keys()->all());
    }

    /**
     * Verify and decode a request into a provider-agnostic result.
     */
    public function result(string $id, Request $request): ProviderResult
    {
        return $this->provider($id)->result($request);
    }

    /**
     * Verify, decode, AND persist a request, dispatching the matching events.
     *
     * The request is always verified synchronously. When queue processing is
     * enabled the verified result is logged to the audit table and recorded on a
     * queue (this method then returns the audit notification); otherwise it is
     * recorded synchronously and the persisted model is returned.
     */
    public function handle(string $id, Request $request): Model
    {
        $result = $this->result($id, $request);

        $notification = $this->audit->execute($result);

        if (config('purchases.queue.enabled', false) === true) {
            ProcessProviderNotification::dispatch($result, $notification?->getKey());

            return $notification ?? $this->placeholderNotification($result);
        }

        $model = $this->sync->execute($result);

        $notification?->update(['processed_at' => Carbon::now()]);

        return $model;
    }

    /**
     * When auditing is disabled but queueing is on, return a transient (unsaved)
     * notification so callers still receive a Model describing what was queued.
     */
    private function placeholderNotification(ProviderResult $result): PurchaseNotification
    {
        $model = PurchaseNotificationModel::class();

        return new $model([
            'provider' => $result->provider(),
            'type' => $result->type()->value,
            'signature_verified' => true,
            'payload' => $result->raw(),
        ]);
    }
}
