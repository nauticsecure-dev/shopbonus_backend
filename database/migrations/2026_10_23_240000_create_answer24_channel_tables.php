<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answer24 integration channel: scoped server-to-server credentials, inbound
 * replay guard, callback history, single-use claim links and reminder slots
 * (Trello #1 "Answer24 exchange", #10 §6, #23 "Answer24 responsibilities").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 120);
            $table->string('target', 32);           // answer24
            $table->string('key_id', 64)->unique(); // sent as X-Shopbonus-Key
            $table->text('secret');                 // encrypted
            $table->json('scopes');
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_inbound_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('integration_clients')->restrictOnDelete();
            $table->string('event_id', 120);
            $table->char('body_hash', 64);
            $table->string('route', 120);
            $table->timestamp('received_at');

            $table->unique(['client_id', 'event_id']);
            $table->index('received_at');
        });

        Schema::create('contact_opportunity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('contact_opportunities')->restrictOnDelete();
            $table->string('status', 24);
            $table->string('outcome', 16);          // applied | stale | duplicate
            $table->string('remote_reference', 120)->nullable();
            $table->string('remote_event_id', 120)->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->unique(['opportunity_id', 'remote_event_id'], 'opp_events_remote_unique');
        });

        Schema::create('voucher_claims', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->unique()->constrained('contact_opportunities')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token');                  // encrypted; lets a repeated handoff return the same link
            $table->string('status', 16);           // open | claimed | expired | cancelled
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('voucher_claim_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained('voucher_claims')->restrictOnDelete();
            $table->unsignedSmallInteger('day');
            $table->timestamp('granted_at');

            // One slot per reminder day: repeated delivery can never send twice.
            $table->unique(['claim_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_claim_reminders');
        Schema::dropIfExists('voucher_claims');
        Schema::dropIfExists('contact_opportunity_events');
        Schema::dropIfExists('integration_inbound_events');
        Schema::dropIfExists('integration_clients');
    }
};
