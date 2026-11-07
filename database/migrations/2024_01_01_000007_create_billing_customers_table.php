<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Purchases\Support\BillingCustomerModel;

return new class extends Migration
{
    public function up(): void
    {
        // The owner (a company, a team, a user) keys like the purchases' owner column, so a
        // ULID or UUID owner model fits.
        $keyType = KeyType::fromConfig('purchases.key_type');

        Schema::create(BillingCustomerModel::new()->getTable(), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('owner', $keyType);
            $table->string('provider');
            $table->string('provider_id');
            $table->timestamps();
            $table->softDeletes();

            // One customer per owner and provider, and one owner per customer.
            $table->unique(['owner_type', 'owner_id', 'provider']);
            $table->unique(['provider', 'provider_id']);
        });
    }
};
