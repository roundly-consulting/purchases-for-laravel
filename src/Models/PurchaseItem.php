<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Purchases\Concerns\HasPrice;
use RoundlyConsulting\Purchases\Database\Factories\PurchaseItemFactory;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * @property int $id
 * @property int $purchase_id
 * @property string|null $provider_id
 * @property string $name
 * @property Money|null $price
 * @property string|null $price_currency
 * @property int $quantity
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class PurchaseItem extends Model
{
    /** @use HasFactory<PurchaseItemFactory> */
    use HasFactory;

    use HasPrice;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'int',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        /** @var class-string<Purchase> $purchase */
        $purchase = config('purchases.models.purchase', Purchase::class);

        return $this->belongsTo($purchase);
    }

    protected static function newFactory(): PurchaseItemFactory
    {
        return PurchaseItemFactory::new();
    }
}
