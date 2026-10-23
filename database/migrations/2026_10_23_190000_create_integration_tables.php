<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ecommerce connections and inbound provider events (Trello #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webshop_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('platform', 32);
            $table->string('method', 32);
            $table->string('status', 24)->default('setup_required');
            // Opaque identifier used in the webhook URL. Not a secret on its own.
            $table->string('webhook_token', 64)->unique();
            // Signing secrets and API tokens. Encrypted; never returned after creation/rotation.
            $table->text('credentials')->nullable();
            $table->string('external_shop_ref')->nullable();
            $table->json('limitations')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_verified_order_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['webshop_id', 'platform', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('connection_id')->constrained('webshop_connections')->restrictOnDelete();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            // Provider event/delivery ID, or a payload hash when the provider sends none.
            $table->string('dedupe_key', 128);
            $table->string('topic', 100)->nullable();
            $table->string('status', 16);  // verified | processed | ignored | failed
            $table->unsignedSmallInteger('delivery_count')->default(1);
            // Raw body, encrypted, pruned after the retention window (Trello #1: no indefinite raw payloads).
            $table->longText('payload')->nullable();
            $table->char('payload_hash', 64);
            $table->string('error_code', 64)->nullable();
            $table->json('result')->nullable();  // safe summary: normalised-order completeness, missing fields
            $table->timestamp('received_at', precision: 6);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('payload_pruned_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'dedupe_key']);
            $table->index(['webshop_id', 'received_at']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webshop_connections');
    }
};
