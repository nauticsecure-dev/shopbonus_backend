<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Answer24 contact eligibility, grouping and the integration outbox
 * (Trello #1 "Answer24 — required processing", #23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshops', function (Blueprint $table) {
            // { enabled, lawful_basis, documented_by, documented_at } — owner attestation.
            $table->json('answer24_settings')->nullable()->after('review_flags');
        });

        Schema::table('webshop_orders', function (Blueprint $table) {
            // Keyed hash identifying "the same customer" within one webshop (grouping, suppression).
            $table->char('customer_key', 64)->nullable()->after('customer_email_hash');
            $table->boolean('contact_consent')->nullable()->after('has_phone');
            $table->json('eligibility_reasons')->nullable()->after('eligibility_status');

            $table->index(['webshop_id', 'customer_key']);
        });

        Schema::create('contact_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete(); // null = all webshops
            $table->string('kind', 8);            // email | phone
            $table->char('subject_hash', 64);
            $table->string('reason', 255)->nullable();
            $table->string('source', 32);         // merchant | consumer | answer24 | admin
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['webshop_id', 'kind', 'subject_hash']);
            $table->index(['kind', 'subject_hash']);
        });

        Schema::create('contact_opportunities', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('customer_key', 64);
            $table->string('status', 24);
            $table->string('lawful_basis', 32);
            $table->timestamp('window_ends_at');
            $table->string('idempotency_key', 120)->unique();
            $table->string('remote_reference', 120)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->timestamps();

            $table->index(['webshop_id', 'customer_key', 'status']);
            $table->index(['status', 'window_ends_at']);
        });

        Schema::create('contact_opportunity_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('contact_opportunities')->restrictOnDelete();
            // An order joins at most one opportunity, ever: no duplicate contact for the same order.
            $table->foreignId('order_id')->unique()->constrained('webshop_orders')->restrictOnDelete();
            $table->string('status', 16);          // active | removed
            $table->string('removed_reason', 64)->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('target', 32);          // answer24
            $table->string('type', 64);
            $table->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->longText('payload');           // encrypted JSON (may contain a phone number)
            $table->string('idempotency_key', 150)->unique();
            $table->string('status', 16);          // pending | sent | failed | cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['target', 'status', 'next_attempt_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_outbox');
        Schema::dropIfExists('contact_opportunity_orders');
        Schema::dropIfExists('contact_opportunities');
        Schema::dropIfExists('contact_suppressions');
        Schema::table('webshop_orders', function (Blueprint $table) {
            $table->dropIndex(['webshop_id', 'customer_key']);
            $table->dropColumn(['customer_key', 'contact_consent', 'eligibility_reasons']);
        });
        Schema::table('webshops', fn (Blueprint $table) => $table->dropColumn('answer24_settings'));
    }
};
