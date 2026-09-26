<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Support\SubscriptionItemModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

return new class extends Migration
{
    public function up(): void
    {
        $subscriptions = SubscriptionModel::new()->getTable();

        Schema::create(SubscriptionItemModel::new()->getTable(), function (Blueprint $table) use ($subscriptions): void {
            $table->id();
            // Named, not `foreignIdFor($subscription)` — see the purchase_items
            // migration: the derived column breaks SubscriptionItem::subscription().
            $table->foreignId('subscription_id')->constrained($subscriptions)->cascadeOnDelete();
            $table->string('provider_id')->nullable();
            $table->string('name');
            $table->money('price', nullable: true);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
