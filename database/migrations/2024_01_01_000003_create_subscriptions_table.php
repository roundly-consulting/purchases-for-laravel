<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Subscription;

return new class extends Migration
{
    public function up(): void
    {
        /** @var class-string<Subscription> $model */
        $model = config('purchases.models.subscription', Subscription::class);

        Schema::create((new $model)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->string('name');
            $table->string('status')->default(Status::New->value)->index();
            $table->integer('price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->timestamp('active_from')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_id']);
        });
    }
};
