<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_claims', fn (Blueprint $t) => $t->index(['webshop_id', 'status', 'claimed_at'], 'claims_workspace_daily'));
        Schema::table('vouchers', function (Blueprint $t) {
            $t->index(['webshop_id', 'issued_at'], 'vouchers_workspace_issued');
            $t->index(['webshop_id', 'redeemed_at'], 'vouchers_workspace_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_claims', fn (Blueprint $t) => $t->dropIndex('claims_workspace_daily'));
        Schema::table('vouchers', function (Blueprint $t) {
            $t->dropIndex('vouchers_workspace_issued');
            $t->dropIndex('vouchers_workspace_redeemed');
        });
    }
};
