<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affiliate tracking, commission reconciliation and reward rules
 * (Trello #19 "Database & API").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_network_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('network', 24);              // daisycon | tradetracker
            $table->string('name', 120);
            $table->string('status', 32);               // connected | limited | reauthentication_required | error | disabled
            $table->text('credentials');                // encrypted JSON
            $table->char('postback_token_hash', 64)->unique();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('affiliate_merchant_programs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('connection_id')->constrained('affiliate_network_connections')->restrictOnDelete();
            $table->string('external_id', 64);
            $table->string('name', 150);
            $table->string('domain', 255)->nullable();
            $table->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->string('commission_type', 16);      // percentage | fixed
            $table->unsignedInteger('commission_value'); // basis points (percentage) or minor units (fixed)
            $table->char('currency', 3)->default('EUR');
            $table->boolean('category_restricted')->default(false);
            $table->boolean('cashback_allowed')->default(true);
            $table->string('tracking_url_template', 1000);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
            $table->index('webshop_id');
        });

        Schema::create('affiliate_program_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('affiliate_merchant_programs')->restrictOnDelete();
            $table->string('method', 24);               // best_comparable | fallback | single | none
            $table->json('candidates');
            $table->string('limitation', 255)->nullable();
            $table->string('rule_version', 32);
            $table->timestamp('created_at')->nullable();

            $table->index(['webshop_id', 'id']);
        });

        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32)->unique();  // opaque, sent to the network as sub ID
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained('affiliate_merchant_programs')->restrictOnDelete();
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('affiliate_reward_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->string('unit', 16);                 // wallet unit of the reward
            $table->unsignedInteger('share_bps');       // reward = commission × share (basis points), rounded down
            $table->unsignedBigInteger('min_commission_minor')->default(0);
            $table->unsignedBigInteger('max_reward_minor')->nullable();
            $table->unsignedBigInteger('review_above_minor')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('affiliate_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('network', 24);
            $table->foreignId('connection_id')->constrained('affiliate_network_connections')->restrictOnDelete();
            $table->string('external_id', 100);
            $table->foreignId('program_id')->nullable()->constrained('affiliate_merchant_programs')->restrictOnDelete();
            $table->foreignId('click_id')->nullable()->constrained('affiliate_clicks')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('order_reference_hash', 64)->nullable();
            $table->unsignedBigInteger('order_amount_minor');
            $table->char('currency', 3);
            $table->unsignedBigInteger('commission_minor');
            $table->string('network_status', 16);       // pending | approved | rejected | reversed
            $table->string('status', 16);               // tracked | pending | approved | rejected | reversed | unmatched | disputed
            $table->string('review_reason', 64)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('affiliate_transactions')->restrictOnDelete();
            $table->string('reward_status', 24)->default('none'); // none | pending | credited | cancelled | reversed | reversal_flagged
            $table->unsignedBigInteger('reward_amount')->nullable();
            $table->string('reward_unit', 16)->nullable();
            $table->unsignedInteger('reward_rule_version')->nullable();
            $table->unsignedBigInteger('wallet_pending_id')->nullable();
            $table->unsignedBigInteger('wallet_entry_id')->nullable();
            $table->timestamp('transaction_at');
            $table->timestamp('network_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['network', 'external_id']);
            $table->index(['status']);
            $table->index(['user_id', 'transaction_at']);
            $table->index(['webshop_id', 'order_reference_hash']);
        });

        Schema::create('affiliate_reconciliation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('affiliate_transactions')->restrictOnDelete();
            $table->string('source', 16);               // sync | import | postback | admin
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('network_status', 16)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['transaction_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_reconciliation_events');
        Schema::dropIfExists('affiliate_transactions');
        Schema::dropIfExists('affiliate_reward_rules');
        Schema::dropIfExists('affiliate_clicks');
        Schema::dropIfExists('affiliate_program_selections');
        Schema::dropIfExists('affiliate_merchant_programs');
        Schema::dropIfExists('affiliate_network_connections');
    }
};
