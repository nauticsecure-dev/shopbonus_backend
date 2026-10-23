<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security/business audit trail. Append-only: rows are never updated.
 *
 * Actor and webshop columns deliberately have no foreign keys: an audit
 * record must survive deletion of the user or webshop it refers to, and an
 * ON DELETE SET NULL cascade would itself be an update of history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', precision: 6)->index();
            $table->string('request_id', 128)->nullable()->index();
            $table->string('category', 32);
            $table->string('action', 100);
            $table->string('outcome', 16);
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            // Differs from actor_user_id only during Super Admin support/impersonation sessions.
            $table->unsignedBigInteger('real_actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('webshop_id')->nullable();
            $table->string('resource_type', 100)->nullable();
            $table->string('resource_id', 100)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('http_method', 10)->nullable();
            // Route template (e.g. api/v1/orders/{order}), never the raw path or query string.
            $table->string('route', 255)->nullable();
            // HMAC of the client IP: correlates repeat sources without storing the address.
            $table->char('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();

            $table->index(['webshop_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['resource_type', 'resource_id']);
        });

        $this->createImmutabilityTrigger();
    }

    public function down(): void
    {
        $this->dropImmutabilityTrigger();
        Schema::dropIfExists('audit_events');
    }

    private function createImmutabilityTrigger(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_block_update BEFORE UPDATE ON audit_events
                FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_events is append-only'
                SQL),
            'sqlite' => DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_block_update BEFORE UPDATE ON audit_events
                BEGIN SELECT RAISE(ABORT, 'audit_events is append-only'); END
                SQL),
            'pgsql' => DB::unprepared(<<<'SQL'
                CREATE FUNCTION audit_events_block_update() RETURNS trigger AS $$
                BEGIN RAISE EXCEPTION 'audit_events is append-only'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER audit_events_block_update BEFORE UPDATE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION audit_events_block_update();
                SQL),
            default => null,
        };
    }

    private function dropImmutabilityTrigger(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb', 'sqlite' => DB::unprepared('DROP TRIGGER IF EXISTS audit_events_block_update'),
            'pgsql' => DB::unprepared(
                'DROP TRIGGER IF EXISTS audit_events_block_update ON audit_events; DROP FUNCTION IF EXISTS audit_events_block_update();'
            ),
            default => null,
        };
    }
};
