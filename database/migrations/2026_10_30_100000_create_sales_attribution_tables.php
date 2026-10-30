<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trello #24: attribution labels, network journeys, promotion funnel events
 * and attribution link evidence. Reuses banner_clicks / banner_assignments;
 * does not duplicate Order Adapter or Wallet tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshop_orders', function (Blueprint $table) {
            $table->string('attribution_type', 24)->default('unattributed')->after('attribution_click_id');
            $table->foreignId('referring_webshop_id')->nullable()->after('attribution_type')->constrained('webshops')->nullOnDelete();
            $table->boolean('is_repeat_order')->default(false)->after('referring_webshop_id');
            $table->index(['webshop_id', 'attribution_type', 'paid_at'], 'webshop_orders_attr_type_paid_idx');
        });

        Schema::create('attribution_links', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained('webshop_orders')->restrictOnDelete();
            $table->string('method', 24); // click | voucher | view
            $table->string('click_id', 40)->nullable()->index();
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('banner_assignment_id')->nullable()->constrained('banner_assignments')->nullOnDelete();
            $table->foreignId('referring_webshop_id')->nullable()->constrained('webshops')->nullOnDelete();
            $table->unsignedSmallInteger('lookback_days');
            $table->timestamp('linked_at');
            $table->unique('order_id'); // one primary attribution link per order
        });

        Schema::create('network_journeys', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('referring_webshop_id')->constrained('webshops')->restrictOnDelete();
            $table->foreignId('receiving_webshop_id')->constrained('webshops')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('webshop_orders')->restrictOnDelete();
            $table->string('click_id', 40)->nullable()->index();
            $table->string('category', 40)->nullable();
            $table->unsignedInteger('order_amount_minor')->default(0);
            $table->char('currency', 3)->default('EUR');
            // Agreed settlement only — never silent deduction from receiving shop.
            $table->string('settlement_status', 32)->default('recorded'); // recorded | pending_agreement | settled | none
            $table->timestamp('attributed_at');
            $table->timestamps();
            $table->unique('order_id');
            $table->index(['referring_webshop_id', 'attributed_at']);
            $table->index(['receiving_webshop_id', 'attributed_at']);
        });

        Schema::create('promotion_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('type', 40); // claim_started|account_verified|voucher_*|order_paid|order_refunded|repeat_order
            $table->string('source', 32); // adapter|voucher|answer24|identity|system
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 40)->nullable();
            $table->json('context')->nullable(); // no PII / payment secrets
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['webshop_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_events');
        Schema::dropIfExists('network_journeys');
        Schema::dropIfExists('attribution_links');
        Schema::table('webshop_orders', function (Blueprint $table) {
            $table->dropIndex('webshop_orders_attr_type_paid_idx');
            $table->dropConstrainedForeignId('referring_webshop_id');
            $table->dropColumn(['attribution_type', 'is_repeat_order']);
        });
    }
};
