<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('price_points')->nullable();
        });
        Schema::table('voucher_checkouts', function (Blueprint $table) {
            $table->string('wallet_unit', 16)->default('eur_credit');
            $table->unsignedBigInteger('price_points')->nullable();
            $table->unsignedBigInteger('wallet_part_points')->default(0);
        });
    }

    public function down(): void
    {
        // Preserve contractual prices and units on existing checkouts and offers.
        // A rollback must not silently reinterpret a completed points purchase.
    }
};
