<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_transactions', function (Blueprint $t) {
            $t->string('reward_timing', 40)->nullable();
            $t->json('reward_terms_snapshot')->nullable();
            $t->json('credit_evidence')->nullable();
        });
        Schema::table('sponsorship_obligations', fn (Blueprint $t) => $t->unsignedInteger('fee_rate_bps')->nullable());
        // Legacy policy evidence cannot be reconstructed from today's configuration.
    }

    public function down(): void
    {
        Schema::table('affiliate_transactions', fn (Blueprint $t) => $t->dropColumn(['reward_timing', 'reward_terms_snapshot', 'credit_evidence']));
        Schema::table('sponsorship_obligations', fn (Blueprint $t) => $t->dropColumn('fee_rate_bps'));
    }
};
