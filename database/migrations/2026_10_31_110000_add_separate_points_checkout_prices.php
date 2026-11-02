<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_versions', function (Blueprint $table) {
            if (! Schema::hasColumn('offer_versions', 'price_points')) {
                $table->unsignedBigInteger('price_points')->nullable();
            }
        });
        Schema::table('voucher_checkouts', function (Blueprint $table) {
            if (! Schema::hasColumn('voucher_checkouts', 'wallet_unit')) {
                $table->string('wallet_unit', 16)->default('eur_credit');
            }
            if (! Schema::hasColumn('voucher_checkouts', 'price_points')) {
                $table->unsignedBigInteger('price_points')->nullable();
            }
            if (! Schema::hasColumn('voucher_checkouts', 'wallet_part_points')) {
                $table->unsignedBigInteger('wallet_part_points')->default(0);
            }
        });
    }

    public function down(): void
    {
        // Preserve contractual prices and units on existing checkouts and offers.
        // A rollback must not silently reinterpret a completed points purchase.
    }
};
