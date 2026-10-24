<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Redemption evidence and voucher disputes (Trello #20 "Redemption &
 * validation", "Cancellations, refunds & disputes").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->string('redemption_source', 16)->nullable()->after('redeemed_order_id'); // order | merchant
            $table->unsignedBigInteger('redeemed_by')->nullable()->after('redemption_source');
            $table->string('redemption_reference', 120)->nullable()->after('redeemed_by');
        });

        Schema::create('voucher_disputes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('voucher_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('checkout_id')->nullable()->constrained('voucher_checkouts')->restrictOnDelete();
            $table->string('reason', 32);
            $table->text('description');
            $table->string('status', 24);              // open | under_review | waiting_for_merchant | resolved | rejected
            $table->string('compensation', 16)->nullable(); // none | wallet_credit | replacement | refund
            $table->unsignedBigInteger('compensation_amount_minor')->nullable();
            $table->string('compensation_ref', 64)->nullable();
            $table->timestamp('compensated_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // One open dispute per subject (NULL while closed so new ones can follow).
            $table->string('open_key', 80)->nullable()->unique();
            $table->timestamps();

            $table->index(['webshop_id', 'status']);
            $table->index(['user_id', 'id']);
        });

        Schema::create('voucher_dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('voucher_disputes')->restrictOnDelete();
            $table->string('actor_role', 16);          // consumer | merchant | admin | system
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('type', 24);                // opened | status_changed | merchant_response | resolved | compensated
            $table->text('note')->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['dispute_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_dispute_events');
        Schema::dropIfExists('voucher_disputes');
        Schema::table('vouchers', fn (Blueprint $table) => $table->dropColumn(['redemption_source', 'redeemed_by', 'redemption_reference']));
    }
};
