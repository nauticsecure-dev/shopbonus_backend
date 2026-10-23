<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Normalised orders and their history (Trello #1 "Normalized orders &
 * database", #2 §5 "unique webshop + external order reference").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webshop_orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('webshop_connections')->restrictOnDelete();
            $table->string('platform', 32);
            $table->string('external_order_id', 100);
            $table->string('reference', 100)->nullable();
            // Customer PII, encrypted. email_hash (HMAC) allows exact-match search without decrypting.
            $table->text('customer_first_name')->nullable();
            $table->text('customer_email')->nullable();
            $table->char('customer_email_hash', 64)->nullable();
            $table->text('customer_phone')->nullable();
            $table->boolean('has_phone')->default(false);
            $table->bigInteger('amount_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->bigInteger('refunded_minor')->default(0);
            // Separate status dimensions (Trello #1: payment, voucher and contact statuses remain separate).
            $table->string('payment_status', 24);
            $table->string('provider_status', 50)->nullable();
            $table->string('ingestion_status', 16);           // complete | incomplete
            $table->json('missing_fields')->nullable();
            $table->string('eligibility_status', 24)->default('not_evaluated'); // B07
            $table->string('contact_status', 24)->default('not_evaluated');     // B07
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('provider_updated_at', precision: 6)->nullable();
            $table->foreignId('last_webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['webshop_id', 'platform', 'external_order_id']);
            $table->index(['webshop_id', 'placed_at']);
            $table->index(['webshop_id', 'payment_status', 'placed_at']);
            $table->index(['webshop_id', 'customer_email_hash']);
            $table->index(['webshop_id', 'reference']);
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('webshop_orders')->restrictOnDelete();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
            $table->string('type', 24);
            $table->string('from_payment_status', 24)->nullable();
            $table->string('to_payment_status', 24)->nullable();
            $table->json('summary')->nullable();  // safe: no PII
            $table->timestamp('provider_updated_at', precision: 6)->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('webshop_orders');
    }
};
