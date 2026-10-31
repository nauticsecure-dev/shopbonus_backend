<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-webshop / per-source consumer share overrides on the existing
 * versioned affiliate_reward_rules table (default remains the global rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_reward_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_reward_rules', 'webshop_id')) {
                $table->foreignId('webshop_id')->nullable()->after('created_by')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('affiliate_reward_rules', 'commission_source')) {
                $table->string('commission_source', 32)->nullable()->after('webshop_id');
            }
            if (! Schema::hasColumn('affiliate_reward_rules', 'cashback_approved')) {
                $table->boolean('cashback_approved')->default(true)->after('commission_source');
            }
        });

        try {
            Schema::table('affiliate_reward_rules', function (Blueprint $table) {
                $table->index(['webshop_id', 'commission_source', 'active'], 'arr_webshop_source_active_idx');
            });
        } catch (Throwable) {
            // Index may already exist from a partial earlier run.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('affiliate_reward_rules', function (Blueprint $table) {
                $table->dropIndex('arr_webshop_source_active_idx');
            });
        } catch (Throwable) {
        }
        try {
            Schema::table('affiliate_reward_rules', function (Blueprint $table) {
                $table->dropIndex('affiliate_reward_rules_webshop_id_commission_source_active_index');
            });
        } catch (Throwable) {
        }

        Schema::table('affiliate_reward_rules', function (Blueprint $table) {
            if (Schema::hasColumn('affiliate_reward_rules', 'webshop_id')) {
                $table->dropConstrainedForeignId('webshop_id');
            }
            $drop = [];
            foreach (['commission_source', 'cashback_approved'] as $col) {
                if (Schema::hasColumn('affiliate_reward_rules', $col)) {
                    $drop[] = $col;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
