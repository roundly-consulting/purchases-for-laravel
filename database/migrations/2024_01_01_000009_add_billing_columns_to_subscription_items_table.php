<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Support\SubscriptionItemModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(SubscriptionItemModel::new()->getTable(), function (Blueprint $table): void {
            // The provider's price the item bills (a Stripe `price_…`).
            $table->string('price_id')->nullable()->index();
            // Seats, licences, units. Null for a metered (usage-based) item, which has none.
            $table->unsignedInteger('quantity')->nullable();
        });
    }
};
