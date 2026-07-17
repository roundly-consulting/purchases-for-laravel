<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

return new class extends Migration
{
    public function up(): void
    {
        // Silently falls back to bigint for an unrecognized value, so a typo in
        // the host's config never leaves the package unable to migrate.
        $keyType = KeyType::fromConfig('purchases.key_type');

        Schema::create(SubscriptionModel::new()->getTable(), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('owner', $keyType, nullable: true);
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
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['provider', 'provider_id']);
        });
    }
};
