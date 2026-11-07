<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Purchases\Database\Factories\BillingCustomerFactory;

/**
 * Links an owner model (a company, a team, a user) to its customer at a billing provider —
 * one Stripe customer per owner. Written by `Purchases::stripe()->customers()->sync()`, read
 * to bill the owner and to find the owner a webhook's customer belongs to.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string $provider
 * @property string $provider_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class BillingCustomer extends Model
{
    /** @use HasFactory<BillingCustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    protected static function newFactory(): BillingCustomerFactory
    {
        return BillingCustomerFactory::new();
    }
}
