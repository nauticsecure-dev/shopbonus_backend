<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_quotas', 'reserved_micro_eur')) {
            Schema::table('support_quotas', fn (Blueprint $t) => $t->unsignedBigInteger('reserved_micro_eur')->default(0));
        }
        // Unknown pre-migration spending must not become a fresh daily allowance.
        foreach (DB::table('support_quotas')->where('scope', 'global')->where('requests', '>', 0)->pluck('day') as $day) {
            DB::table('support_quotas')->insertOrIgnore(['day' => $day, 'scope' => 'budget:eur', 'requests' => 0, 'reserved_micro_eur' => 1000000000]);
        }
        if (! Schema::hasTable('support_model_verifications')) {
            Schema::create('support_model_verifications', function (Blueprint $t) {
                $t->char('fingerprint', 64)->primary();
                $t->string('model', 100);
                $t->timestamp('verified_at');
                $t->timestamp('expires_at');
            });
        }
    }

    public function down(): void
    {
        // Retain spending/proof history: rollback must not reset today's budget.
    }
};
