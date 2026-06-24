<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Purchase;
use RoundlyConsulting\Purchases\PurchaseItem;

return new class extends Migration
{
    public function up(): void
    {
        /** @var class-string<PurchaseItem> $model */
        $model = config('purchases.models.purchase-item', PurchaseItem::class);
        /** @var class-string<Purchase> $purchase */
        $purchase = config('purchases.models.purchase', Purchase::class);

        Schema::create((new $model)->getTable(), function (Blueprint $table) use ($purchase): void {
            $table->id();
            $table->foreignIdFor($purchase)->constrained((new $purchase)->getTable())->cascadeOnDelete();
            $table->string('provider_id')->nullable();
            $table->string('name');
            $table->integer('price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
