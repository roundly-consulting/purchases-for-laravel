<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(PurchaseNotificationModel::new()->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->index();
            $table->string('type')->nullable()->index();
            $table->boolean('signature_verified')->default(false);
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
