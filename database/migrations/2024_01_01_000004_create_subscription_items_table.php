<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;

return new class extends Migration
{
    public function up(): void
    {
        /** @var class-string<SubscriptionItem> $model */
        $model = config('purchases.models.subscription-item', SubscriptionItem::class);
        /** @var class-string<Subscription> $subscription */
        $subscription = config('purchases.models.subscription', Subscription::class);

        Schema::create((new $model)->getTable(), function (Blueprint $table) use ($subscription): void {
            $table->id();
            // Named, not `foreignIdFor($subscription)` — see the purchase_items
            // migration: the derived column breaks SubscriptionItem::subscription().
            $table->foreignId('subscription_id')->constrained((new $subscription)->getTable())->cascadeOnDelete();
            $table->string('provider_id')->nullable();
            $table->string('name');
            $table->integer('price')->nullable();
            $table->string('price_currency', 3)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
