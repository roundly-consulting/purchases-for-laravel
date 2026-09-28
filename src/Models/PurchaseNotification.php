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
use RoundlyConsulting\Purchases\Enum\NotificationOrigin;

/**
 * An auditable record of every verified raw notification payload, captured before
 * it is reduced to purchase / subscription / refund state — and of every result a host
 * handed to `Purchases::sync()`, which the package did not verify (`signature_verified`
 * false, origin `host`; see origin()).
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

    /**
     * Who produced this row: a verified provider notification, or a host-supplied result.
     * A snapshot without the marker (or with an unknown one) reads as Provider, so it is held
     * to the verification rule rather than waved through.
     */
    public function origin(): NotificationOrigin
    {
        $origin = $this->payload->get('origin');

        return (is_string($origin) ? NotificationOrigin::tryFrom($origin) : null) ?? NotificationOrigin::Provider;
    }

    protected static function newFactory(): PurchaseNotificationFactory
    {
        return PurchaseNotificationFactory::new();
    }
}
