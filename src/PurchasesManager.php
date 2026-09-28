<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Actions\HandleProviderResultAction;
use RoundlyConsulting\Purchases\Actions\ReplayProviderNotificationAction;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Exceptions\UnknownProviderException;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Providers\Provider;
use RoundlyConsulting\Purchases\Providers\Resolver;

/**
 * The root behind the `Purchases` facade, and the class to inject when you prefer
 * dependency injection: resolve providers, decode results, and persist them.
 *
 * Every persisting method is a thin call into an action resolved from the container, so the
 * facade, an injected manager and the raw action all run the same code — and `Purchases::fake()`
 * (a subtype of this class) sees every call.
 */
class PurchasesManager
{
    public function __construct(
        protected readonly Container $container,
        private readonly Resolver $resolver,
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
     * Verify and decode a request into a provider-agnostic result. Persists nothing.
     */
    public function result(string $id, Request $request): ProviderResult
    {
        return $this->provider($id)->result($request);
    }

    /**
     * Verify, decode, AND persist a request, dispatching the matching events.
     *
     * The request is always verified synchronously; persistence honours
     * `purchases.queue.enabled`. Always returns a Model — see HandleProviderResultAction.
     */
    public function handle(string $id, Request $request): Model
    {
        return $this->container->make(HandleProviderResultAction::class)->execute($this->result($id, $request));
    }

    /**
     * Persist a result you already hold (already verified), audited like a webhook and
     * always synchronously. Returns the Purchase, Subscription or PurchaseRefund, or null
     * for an informational result.
     */
    public function sync(ProviderResult $result): ?Model
    {
        return $this->container->make(SyncProviderResultAction::class)->execute($result);
    }

    /**
     * Re-run one stored audit notification (a model or its id) through the recording
     * pipeline and mark it processed. Returns the persisted model, or null for an
     * informational notification.
     */
    public function replay(PurchaseNotification|int $notification): ?Model
    {
        return $this->container->make(ReplayProviderNotificationAction::class)->execute($notification);
    }

    /**
     * One owner's purchases and subscriptions, scoped to exactly that owner.
     */
    public function for(Model $owner): OwnerPurchases
    {
        return new OwnerPurchases($owner);
    }
}
