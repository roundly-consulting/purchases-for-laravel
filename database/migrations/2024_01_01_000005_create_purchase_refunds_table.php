<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;

return new class extends Migration
{
    public function up(): void
    {
        /** @var class-string<PurchaseRefund> $model */
        $model = config('purchases.models.purchase-refund', PurchaseRefund::class);

        Schema::create((new $model)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_id')->nullable()->index();
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('reason')->nullable();
            $table->boolean('chargeback')->default(false)->index();
            $table->integer('price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_id']);
        });
    }
};
