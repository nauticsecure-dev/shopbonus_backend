<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_reconciliation_checks', function (Blueprint $t) {
            $t->index(['run_id', 'status', 'id'], 'finance_check_status_page');
        });
    }

    public function down(): void
    {
        Schema::table('financial_reconciliation_checks', function (Blueprint $t) {
            $t->dropIndex('finance_check_status_page');
        });
    }
};
