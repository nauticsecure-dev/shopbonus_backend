<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offers and their versioned terms (Trello #7 §3–4). Issued vouchers point
 * to the exact version they were issued under, so editing an offer never
 * changes the terms of vouchers already issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            // How consumers obtain the voucher; one voucher system, different acquisition (Trello #23).
            $table->string('acquisition', 24);
            $table->string('status', 16)->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['webshop_id', 'status']);
        });

        Schema::create('offer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 150);
            $table->text('public_description')->nullable();
            $table->string('discount_type', 16);                  // percentage | fixed
            $table->unsignedInteger('discount_value');            // basis points (percentage) or minor units (fixed)
            $table->char('currency', 3)->default('EUR');
            $table->unsignedBigInteger('min_order_minor')->nullable();
            $table->unsignedBigInteger('max_discount_minor')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();  // wallet_purchase only
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('claim_validity_days')->nullable();
            $table->unsignedSmallInteger('redemption_validity_days')->nullable();
            $table->json('categories')->nullable();
            $table->json('products')->nullable();
            $table->boolean('combinable')->default(false);
            $table->string('code_mode', 16)->default('unique');    // unique | reusable
            $table->unsignedInteger('allocation_limit')->nullable();
            // Frozen once the offer is activated or a voucher references it.
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['offer_id', 'version']);
        });

        Schema::table('offers', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('offer_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('offer_versions');
        Schema::dropIfExists('offers');
    }
};
