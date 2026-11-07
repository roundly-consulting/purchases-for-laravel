<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host's ULID-keyed companies — the billable owner of a B2B subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }
};
