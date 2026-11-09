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
        Schema::table(PurchaseNotificationModel::new()->getTable(), function (Blueprint $table): void {
            // The provider's event id (a Stripe `evt_…`, an Apple notificationUUID, a Google
            // Pub/Sub messageId), written when `purchases.audit.deduplicate` is on. Unique per
            // provider, so two deliveries of one event racing each other store it once; rows
            // without one (null) never collide.
            $table->string('event_id')->nullable();
            // When a delivery last took the event on. A redelivery waits while it is recent.
            $table->timestamp('claimed_at')->nullable();

            $table->unique(['provider', 'event_id']);
        });
    }
};
