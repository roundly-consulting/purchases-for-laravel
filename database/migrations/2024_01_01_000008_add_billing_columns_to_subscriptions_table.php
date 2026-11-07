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
            // Stripe: the subscription ends at `ends_at` instead of renewing. Apple and Google
            // never set it.
            $table->boolean('cancel_at_period_end')->default(false);
        });
    }
};
