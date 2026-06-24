<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;

return new class extends Migration
{
    public function up(): void
    {
        /** @var class-string<Purchase> $model */
        $model = config('purchases.models.purchase', Purchase::class);

        Schema::create((new $model)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->string('status')->default(Status::New->value)->index();
            $table->integer('price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_id']);
        });
    }
};
