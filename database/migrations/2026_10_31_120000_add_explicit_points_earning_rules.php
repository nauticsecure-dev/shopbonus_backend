<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_reward_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliate_reward_rules', 'program_id')) {
                $table->foreignId('program_id')->nullable()->constrained('affiliate_merchant_programs')->restrictOnDelete();
            }
            if (! Schema::hasColumn('affiliate_reward_rules', 'calculation_version')) {
                $table->string('calculation_version', 24)->default('legacy_v1');
            }
            if (! Schema::hasColumn('affiliate_reward_rules', 'points_per_eur_share')) {
                $table->unsignedInteger('points_per_eur_share')->nullable();
            }
            if (! Schema::hasColumn('affiliate_reward_rules', 'points_multiplier_bps')) {
                $table->unsignedInteger('points_multiplier_bps')->default(10000);
            }
            if (! Schema::hasColumn('affiliate_reward_rules', 'points_rounding')) {
                $table->string('points_rounding', 16)->default('floor');
            }
            if (! Schema::hasIndex('affiliate_reward_rules', 'reward_program_active_idx')) {
                $table->index(['program_id', 'active'], 'reward_program_active_idx');
            }
        });
    }

    public function down(): void
    {
        // Preserve historical calculation terms and source references.
    }
};
