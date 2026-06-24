<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Exceptions\UnknownProviderException;
use RoundlyConsulting\Purchases\Providers\Provider;
use RoundlyConsulting\Purchases\Providers\Resolver;

/**
 * The expressive entry point for the package: resolve providers, decode results,
 * and persist them in a single call.
 */
final class Purchases
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly SyncProviderResultAction $sync = new SyncProviderResultAction,
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
     */
    public function handle(string $id, Request $request): Model
    {
        return $this->sync->execute($this->result($id, $request));
    }
}
