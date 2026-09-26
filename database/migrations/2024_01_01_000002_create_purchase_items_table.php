<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Support\PurchaseItemModel;
use RoundlyConsulting\Purchases\Support\PurchaseModel;

return new class extends Migration
{
    public function up(): void
    {
        $purchases = PurchaseModel::new()->getTable();

        Schema::create(PurchaseItemModel::new()->getTable(), function (Blueprint $table) use ($purchases): void {
            $table->id();
            // Named, not `foreignIdFor($purchase)`: that derives the column from the
            // configured class's name, so a host subclass would get a
            // `custom_purchase_id` column that PurchaseItem::purchase() (which keys
            // off the relation name) could never find.
            $table->foreignId('purchase_id')->constrained($purchases)->cascadeOnDelete();
            $table->string('provider_id')->nullable();
            $table->string('name');
            $table->money('price', nullable: true);
            $table->integer('quantity')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
