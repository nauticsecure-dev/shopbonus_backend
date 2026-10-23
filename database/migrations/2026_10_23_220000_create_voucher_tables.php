<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One code inventory and one voucher model (Trello #7, #23 "One voucher system").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_code_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->string('source', 16);              // paste | file
            $table->string('status', 16);              // preview | committing | committed | discarded | failed
            $table->json('totals');
            $table->json('rejections')->nullable();    // masked codes + reasons only
            $table->longText('payload')->nullable();   // encrypted list of valid codes; cleared after commit
            $table->timestamp('expires_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['offer_id', 'status']);
        });

        Schema::create('voucher_codes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('voucher_code_batches')->restrictOnDelete();
            $table->text('code');                      // encrypted
            $table->char('code_hash', 64);             // HMAC of the upper-cased code, per webshop scope
            $table->string('code_last4', 4);
            $table->string('status', 16)->default('available');
            $table->string('platform_validation', 32)->default('merchant_verification_required');
            $table->boolean('reusable')->default(false);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalid_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['webshop_id', 'code_hash']);
            $table->index(['offer_id', 'status', 'id']);
            $table->index(['batch_id']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_code_id')->constrained()->restrictOnDelete();
            $table->string('origin', 24);              // merchant_reward | answer24_claim | wallet_purchase | promotional
            $table->string('status', 16);
            $table->foreignId('consumer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('source_order_id')->nullable()->constrained('webshop_orders')->restrictOnDelete();
            // Exactly-once guard: e.g. "order:<id>:offer:<id>" (one reward per order) or a checkout ID.
            $table->string('idempotency_key', 150);
            $table->json('terms_snapshot');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_order_id')->nullable()->constrained('webshop_orders')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['webshop_id', 'idempotency_key']);
            $table->index(['offer_id', 'status']);
            $table->index(['consumer_user_id', 'status']);
            $table->index(['status', 'claim_expires_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('voucher_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('voucher_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['voucher_code_id', 'id']);
            $table->index(['voucher_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_events');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_codes');
        Schema::dropIfExists('voucher_code_batches');
    }
};
