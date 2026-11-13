<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(SubscriptionModel::new()->getTable(), function (Blueprint $table): void {
            // Stripe: when a scheduled cancellation takes effect (`cancel_at`) — a date chosen
            // with cancelAt(), or the period end cancel_at_period_end resolves to. Apple and
            // Google never set it.
            $table->timestamp('cancels_at')->nullable();
        });
    }
};
