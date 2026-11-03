<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('acquisition_transaction_id')->nullable()->constrained('affiliate_transactions')->restrictOnDelete();
        });
        Schema::table('voucher_codes', fn (Blueprint $table) => $table->index('code_hash'));
    }

    public function down(): void
    {
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropConstrainedForeignId('acquisition_transaction_id'));
        Schema::table('voucher_codes', fn (Blueprint $table) => $table->dropIndex(['code_hash']));
    }
};
