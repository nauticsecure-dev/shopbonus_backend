<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mollie refunds are asynchronous (queued → pending → processing → refunded,
 * or failed/canceled). Store the provider status instead of assuming success.
 * Existing rows keep null: their refunds predate this tracking (unknown).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_checkout_payments', function (Blueprint $table) {
            $table->string('refund_status', 16)->nullable()->after('refund_ref');
            $table->unsignedBigInteger('refund_amount_minor')->nullable()->after('refund_status');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_checkout_payments', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'refund_amount_minor']);
        });
    }
};
