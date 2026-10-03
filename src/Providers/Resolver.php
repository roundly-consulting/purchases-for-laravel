<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers;

use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

final class Resolver
{
    /** @var list<class-string<Provider>> */
    protected array $providers;

    public function __construct()
    {
        $this->providers = PurchasesConfig::providers();
    }

    public function resolve(string $id): ?Provider
    {
        return $this->all()->get($id);
    }

    /**
     * @return Collection<int, string>
     */
    public function keys(): Collection
    {
        return $this->all()->keys();
    }

    /**
     * @return Collection<string, Provider>
     */
    public function all(): Collection
    {
        return collect($this->providers)->mapWithKeys(function (string $provider): array {
            $instance = resolve($provider);

            return [
                $instance->id() => $instance,
            ];
        });
    }
}
