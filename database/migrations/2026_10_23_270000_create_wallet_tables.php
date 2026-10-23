<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central non-cash wallet and append-only ledger (Trello #19 "Central
 * wallet", #23 "Instant affiliate credit"). Every amount is an integer in
 * the wallet's unit; there is no implicit conversion between units
 * (OPEN-REWARD-UNITS safe default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('owner_type', 16);        // user | webshop
            $table->unsignedBigInteger('owner_id');
            $table->string('kind', 24);              // consumer_credit | merchant_funding (B12)
            $table->string('unit', 16);              // eur_credit (minor units) | points
            // Cached balances, updated in the same transaction as the ledger entry.
            // Unsigned: the database itself refuses a negative balance.
            $table->unsignedBigInteger('available')->default(0);
            $table->unsignedBigInteger('reserved')->default(0);
            $table->unsignedBigInteger('pending')->default(0);
            $table->unsignedBigInteger('total_earned')->default(0);
            $table->unsignedBigInteger('total_spent')->default(0);
            $table->unsignedBigInteger('total_expired')->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'kind', 'unit'], 'wallets_owner_unique');
        });

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);              // credit | debit | reserve | release | expire | adjustment | reversal
            $table->bigInteger('amount');            // signed effect on available + reserved (spendable total)
            $table->bigInteger('available_delta');
            $table->bigInteger('reserved_delta');
            $table->unsignedBigInteger('available_after');
            $table->unsignedBigInteger('reserved_after');
            $table->string('source_type', 40)->nullable();
            $table->string('source_ref', 120)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('origin', 16);            // system | consumer | admin
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('idempotency_key', 150);
            $table->unsignedBigInteger('related_entry_id')->nullable();
            $table->timestamp('expires_at')->nullable();   // credits only
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->unique(['wallet_id', 'idempotency_key']);
            $table->index(['wallet_id', 'id']);
            $table->index(['source_type', 'source_ref']);
        });

        Schema::create('wallet_credit_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('entry_id')->unique()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('remaining');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'remaining', 'expires_at']);
        });

        Schema::create('wallet_reservations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 16);            // active | captured | released
            $table->string('source_type', 40)->nullable();
            $table->string('source_ref', 120)->nullable();
            $table->string('idempotency_key', 150);
            $table->json('allocations');             // [{lot_id, amount}] taken oldest-expiring first
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'idempotency_key']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('wallet_pending_credits', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 16);            // pending | confirmed | cancelled
            $table->string('source_type', 40)->nullable();
            $table->string('source_ref', 120)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('idempotency_key', 150);
            $table->foreignId('credit_entry_id')->nullable()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'idempotency_key']);
            $table->index(['wallet_id', 'status']);
        });

        Schema::create('wallet_reconciliation_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('reason', 64);            // reversal_exceeds_unspent | balance_mismatch
            $table->bigInteger('amount')->default(0);
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamps();

            $table->index(['status', 'reason']);
        });

        $this->appendOnly('wallet_ledger_entries');
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $op) {
            match (DB::getDriverName()) {
                'mysql', 'mariadb', 'sqlite' => DB::unprepared("DROP TRIGGER IF EXISTS wallet_ledger_entries_block_{$op}"),
                default => null,
            };
        }
        Schema::dropIfExists('wallet_reconciliation_flags');
        Schema::dropIfExists('wallet_pending_credits');
        Schema::dropIfExists('wallet_reservations');
        Schema::dropIfExists('wallet_credit_lots');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallets');
    }

    /** Posted ledger entries can never be changed or removed, not even with raw SQL. */
    private function appendOnly(string $table): void
    {
        foreach (['update' => 'UPDATE', 'delete' => 'DELETE'] as $op => $sql) {
            match (DB::getDriverName()) {
                'mysql', 'mariadb' => DB::unprepared("CREATE TRIGGER {$table}_block_{$op} BEFORE {$sql} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table} is append-only'"),
                'sqlite' => DB::unprepared("CREATE TRIGGER {$table}_block_{$op} BEFORE {$sql} ON {$table} BEGIN SELECT RAISE(ABORT, '{$table} is append-only'); END"),
                default => null, // PostgreSQL variant added with the first PG deployment (NOT RUN; see B01b).
            };
        }
    }
};
