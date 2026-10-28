<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_events', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('status', 20);
            $t->ulid('archive_ref')->nullable();
            $t->json('metrics');
            $t->timestamp('observed_at');
            $t->index(['kind', 'status', 'observed_at'], 'recovery_kind_status_time');
        });
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $op => $sql) {
            match (DB::getDriverName()) {
                'mysql', 'mariadb' => DB::unprepared("CREATE TRIGGER recovery_events_block_{$op} BEFORE {$sql} ON recovery_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Recovery events are append-only'"),
                'sqlite' => DB::unprepared("CREATE TRIGGER recovery_events_block_{$op} BEFORE {$sql} ON recovery_events BEGIN SELECT RAISE(ABORT, 'Recovery events are append-only'); END"),
                default => null,
            };
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recovery_events');
    }
};
