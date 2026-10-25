<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_reconciliation_flags', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->unique();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_reason', 500)->nullable();
        });
        DB::table('wallet_reconciliation_flags')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('wallet_reconciliation_flags')->where('id', $row->id)->update(['public_id' => strtolower((string) Str::ulid())]);
            }
        });
        Schema::table('wallet_reconciliation_flags', fn (Blueprint $table) => $table->char('public_id', 26)->nullable(false)->change());
    }

    public function down(): void
    {
        Schema::table('wallet_reconciliation_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['public_id', 'resolved_at', 'resolution_reason']);
        });
    }
};
