<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider / identifier query scopes shared by purchases, subscriptions, and refunds.
 *
 * @phpstan-require-extends Model
 */
trait HasProviderScopes
{
    /**
     * Limit results to a single provider (e.g. "stripe").
     *
     * @param  Builder<static>  $query
     */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    /**
     * Find by the provider-side identifier the record is keyed on.
     *
     * @param  Builder<static>  $query
     */
    public function scopeByProviderId(Builder $query, string $providerId): void
    {
        $query->where('provider_id', $providerId);
    }

    /**
     * Find by the underlying transaction identifier.
     *
     * @param  Builder<static>  $query
     */
    public function scopeByTransaction(Builder $query, string $transactionId): void
    {
        $query->where('transaction_id', $transactionId);
    }
}
