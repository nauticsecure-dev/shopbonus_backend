<?php

use App\Services\Audit\AuditIntegrity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->char('last_hash', 64);
            $table->unsignedBigInteger('entry_count');
        });
        Schema::create('audit_event_seals', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('audit_event_id')->unique()->constrained('audit_events')->restrictOnDelete();
            $table->char('previous_hash', 64);
            $table->char('event_hash', 64);
            $table->char('chain_hash', 64);
        });
        app(AuditIntegrity::class)->backfill();
        foreach (['update', 'delete'] as $op) {
            match (DB::getDriverName()) {
                'mysql', 'mariadb' => DB::unprepared("CREATE TRIGGER audit_event_seals_block_{$op} BEFORE {$op} ON audit_event_seals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_event_seals is append-only'"),
                'sqlite' => DB::unprepared("CREATE TRIGGER audit_event_seals_block_{$op} BEFORE {$op} ON audit_event_seals BEGIN SELECT RAISE(ABORT, 'audit_event_seals is append-only'); END"),
                default => null,
            };
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $op) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
                DB::unprepared("DROP TRIGGER IF EXISTS audit_event_seals_block_{$op}");
            }
        }
        Schema::dropIfExists('audit_event_seals');
        Schema::dropIfExists('audit_chain_heads');
    }
};
