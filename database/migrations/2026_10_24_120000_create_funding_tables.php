<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant funding of sponsored rewards, top-ups and invoice drafts
 * (Trello #24 §4 "Merchant wallet, 30% fee, invoices").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->char('period', 7);                     // YYYY-MM
            $table->unsignedBigInteger('reward_minor');
            $table->unsignedBigInteger('fee_minor');
            $table->unsignedInteger('lines');
            $table->string('status', 16);                  // draft | sent
            $table->string('external_ref', 64)->nullable(); // Moneybird invoice ID (not connected yet)
            $table->timestamps();

            $table->unique(['webshop_id', 'period']);
        });

        Schema::create('sponsorship_obligations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('source_order_id')->nullable()->constrained('webshop_orders')->restrictOnDelete();
            $table->unsignedBigInteger('reward_minor');
            $table->unsignedBigInteger('fee_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('basis', 24);                   // fixed | maximum_discount
            $table->string('status', 16);                  // reserved | settled | released
            $table->foreignId('wallet_reservation_id')->constrained('wallet_reservations')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('funding_invoices')->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['webshop_id', 'status']);
            $table->index(['status', 'settled_at']);
        });

        Schema::create('funding_top_ups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('state', 16);                   // pending | paid | failed
            $table->string('provider_ref', 64)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('credit_entry_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_top_ups');
        Schema::dropIfExists('sponsorship_obligations');
        Schema::dropIfExists('funding_invoices');
    }
};
