<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Database\Factories\PurchaseNotificationFactory;

/**
 * An auditable record of every verified raw notification payload, captured before
 * it is reduced to purchase / subscription / refund state.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $type
 * @property bool $signature_verified
 * @property Collection<array-key, mixed> $payload
 * @property CarbonInterface|null $processed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class PurchaseNotification extends Model
{
    /** @use HasFactory<PurchaseNotificationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_verified' => 'bool',
            'payload' => 'collection',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForProvider(Builder $query, string $provider): void
    {
        $query->where('provider', $provider);
    }

    /**
     * Notifications recorded but not yet processed into model state.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('processed_at');
    }

    protected static function newFactory(): PurchaseNotificationFactory
    {
        return PurchaseNotificationFactory::new();
    }
}
