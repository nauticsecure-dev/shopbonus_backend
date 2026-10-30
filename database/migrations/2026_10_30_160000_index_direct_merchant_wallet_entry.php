<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed orphan-credit lookups and enforce one conversion per ledger credit.
 * Existing unique (webshop_id, external_id) and idempotency_key remain authoritative for ingest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_commission_conversions', function (Blueprint $table) {
            $table->unique('wallet_entry_id', 'mcc_wallet_entry_unique');
            $table->index(['status', 'converted_at'], 'mcc_status_converted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_commission_conversions', function (Blueprint $table) {
            $table->dropUnique('mcc_wallet_entry_unique');
            $table->dropIndex('mcc_status_converted_idx');
        });
    }
};
