<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authorised catalogue snapshots from connected platforms.
 * Never duplicate order tables; products/coupons are sync projections only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_products', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained('webshops')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('external_product_id', 120);
            $table->string('sku', 120)->nullable();
            $table->string('name', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('product_url', 1000)->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->string('category', 255)->nullable();
            $table->bigInteger('price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('availability', 40)->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['connection_id', 'external_product_id']);
            $table->index(['webshop_id', 'synced_at']);
        });

        Schema::create('catalog_coupons', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained('webshops')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('external_coupon_id', 120);
            $table->string('code', 64);
            $table->string('discount_type', 40)->nullable();
            $table->string('discount_value', 40)->nullable();
            $table->string('minimum_spend', 40)->nullable();
            $table->json('excluded_categories')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->json('usage_limits')->nullable();
            $table->json('stacking_rules')->nullable();
            $table->string('status', 40)->nullable();
            $table->boolean('eligible_public')->default(false);
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['connection_id', 'external_coupon_id']);
            $table->index(['webshop_id', 'code']);
        });

        Schema::create('catalog_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained('webshops')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->string('resource', 20);
            $table->string('status', 20);
            $table->unsignedInteger('imported')->default(0);
            $table->string('cursor', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['connection_id', 'resource', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_sync_runs');
        Schema::dropIfExists('catalog_coupons');
        Schema::dropIfExists('catalog_products');
    }
};
