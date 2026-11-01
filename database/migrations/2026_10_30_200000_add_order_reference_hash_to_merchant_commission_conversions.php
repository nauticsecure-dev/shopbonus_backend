<?php

use App\Services\Cashback\RewardSourceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed cross-path duplicate check (Trello #19 review): affiliate crediting
 * compared order-reference hashes against only the first 500 credited direct
 * conversions. Store the same keyed hash on each conversion and backfill it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_commission_conversions', function (Blueprint $table) {
            $table->string('order_reference_hash', 64)->nullable()->after('external_id');
            $table->index(['webshop_id', 'order_reference_hash'], 'mcc_order_reference_idx');
        });

        $guard = new RewardSourceGuard;
        DB::table('merchant_commission_conversions')->whereNull('order_reference_hash')->orderBy('id')
            ->chunkById(500, function ($rows) use ($guard) {
                foreach ($rows as $row) {
                    DB::table('merchant_commission_conversions')->where('id', $row->id)
                        ->update(['order_reference_hash' => $guard->orderReferenceHash((int) $row->webshop_id, (string) $row->external_id)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('merchant_commission_conversions', function (Blueprint $table) {
            $table->dropIndex('mcc_order_reference_idx');
            $table->dropColumn('order_reference_hash');
        });
    }
};
