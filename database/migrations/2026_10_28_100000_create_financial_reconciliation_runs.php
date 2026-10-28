<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_reconciliation_runs', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('run_key', 100)->unique();
            $t->string('status', 20)->default('pending')->index();
            $t->unsignedBigInteger('wallet_watermark');
            $t->unsignedBigInteger('wallet_cursor')->default(0);
            $t->unsignedBigInteger('checked')->default(0);
            $t->unsignedBigInteger('discrepancies')->default(0);
            $t->string('failure_class', 160)->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('financial_reconciliation_checks', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('run_id')->constrained('financial_reconciliation_runs')->restrictOnDelete();
            $t->string('source_type', 40);
            $t->ulid('source_ref');
            $t->string('status', 20);
            $t->json('evidence');
            $t->timestamp('created_at');
            $t->unique(['run_id', 'source_type', 'source_ref'], 'finance_check_source_unique');
        });
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $op => $sql) {
            match (DB::getDriverName()) {
                'mysql', 'mariadb' => DB::unprepared("CREATE TRIGGER finance_checks_block_{$op} BEFORE {$sql} ON financial_reconciliation_checks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Financial checks are append-only'"),
                'sqlite' => DB::unprepared("CREATE TRIGGER finance_checks_block_{$op} BEFORE {$sql} ON financial_reconciliation_checks BEGIN SELECT RAISE(ABORT, 'Financial checks are append-only'); END"),
                default => null,
            };
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reconciliation_checks');
        Schema::dropIfExists('financial_reconciliation_runs');
    }
};
