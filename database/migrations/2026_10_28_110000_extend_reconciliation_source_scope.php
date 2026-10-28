<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_reconciliation_runs', function (Blueprint $t) {
            $t->unsignedSmallInteger('scope_version')->default(1);
            $t->json('source_state')->nullable();
            $t->unsignedBigInteger('unknown_checks')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('financial_reconciliation_runs', fn (Blueprint $t) => $t->dropColumn(['scope_version', 'source_state', 'unknown_checks']));
    }
};
