<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_transactions', function (Blueprint $table) {
            $table->json('settlement_evidence')->nullable();
        });
        Schema::table('offer_versions', function (Blueprint $table) {
            $table->json('cashback_permission')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_transactions', fn (Blueprint $table) => $table->dropColumn('settlement_evidence'));
        Schema::table('offer_versions', fn (Blueprint $table) => $table->dropColumn('cashback_permission'));
    }
};
