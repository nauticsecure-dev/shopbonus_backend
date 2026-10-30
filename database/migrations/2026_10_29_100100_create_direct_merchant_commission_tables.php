<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct-merchant commission agreements and verified conversions.
 * Credits always go through WalletService; never duplicate ledger math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_commission_agreements', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('commission_type', 16); // percentage | fixed
            $table->unsignedInteger('commission_rate_bps')->nullable(); // e.g. 1200 = 12%
            $table->unsignedBigInteger('commission_fixed_minor')->nullable();
            $table->unsignedInteger('consumer_share_bps'); // e.g. 9000 = 90%
            $table->unsignedInteger('shopbonus_share_bps'); // must total 10000 with consumer
            $table->boolean('cashback_approved')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['webshop_id', 'version']);
            $table->index(['webshop_id', 'active', 'cashback_approved'], 'mca_webshop_active_approved_idx');
        });

        Schema::create('merchant_commission_conversions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_id')->nullable()->constrained('merchant_commission_agreements')->nullOnDelete();
            $table->string('external_id', 100);
            $table->string('idempotency_key', 120)->unique();
            $table->unsignedBigInteger('order_amount_minor');
            $table->unsignedBigInteger('commission_minor');
            $table->unsignedBigInteger('consumer_credit_minor');
            $table->unsignedBigInteger('shopbonus_revenue_minor');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 24)->default('credited'); // credited | reversed | exception
            $table->string('exception_reason', 64)->nullable();
            $table->unsignedBigInteger('wallet_entry_id')->nullable();
            $table->json('agreement_snapshot');
            $table->timestamp('converted_at');
            $table->timestamps();

            $table->unique(['webshop_id', 'external_id']);
            $table->index(['user_id', 'converted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_commission_conversions');
        Schema::dropIfExists('merchant_commission_agreements');
    }
};
