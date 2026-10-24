<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher checkout with wallet credit and/or Mollie (Trello #20 "Checkout
 * process", "Mollie payments"; #23 "Wallet/Mollie voucher").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_checkouts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 100);
            // pending_payment | completed | failed | expired | paid_unfulfilled | refunded
            $table->string('state', 24);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('wallet_part_minor');
            $table->unsignedBigInteger('payment_part_minor');
            $table->char('currency', 3);
            $table->foreignId('wallet_reservation_id')->nullable()->constrained('wallet_reservations')->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['state', 'expires_at']);
        });

        Schema::create('voucher_checkout_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->unique()->constrained('voucher_checkouts')->restrictOnDelete();
            $table->string('provider', 16);              // mollie
            $table->string('provider_ref', 64)->unique(); // tr_...
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16);                // open | pending | authorized | paid | failed | canceled | expired
            $table->text('checkout_url')->nullable();
            $table->boolean('test_mode');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('refund_ref', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_checkout_payments');
        Schema::dropIfExists('voucher_checkouts');
    }
};
