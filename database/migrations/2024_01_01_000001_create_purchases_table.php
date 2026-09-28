<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\PurchaseModel;

return new class extends Migration
{
    public function up(): void
    {
        // Silently falls back to bigint for an unrecognized value, so a typo in
        // the host's config never leaves the package unable to migrate.
        $keyType = KeyType::fromConfig('purchases.key_type');

        Schema::create(PurchaseModel::new()->getTable(), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('owner', $keyType, nullable: true);
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('transaction_id')->nullable()->index();
            $table->string('status')->default(Status::New->value)->index();
            $table->money('price', nullable: true);
            $table->jsonb('meta')->nullable();
            // The provider time of the latest event applied to this row: an older event
            // (a redelivery, a replay, an out-of-order delivery) never overwrites it.
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_id']);
        });
    }
};
