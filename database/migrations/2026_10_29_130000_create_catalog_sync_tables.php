<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only catalog and native coupon snapshots per connection (Trello #1 §4,
 * #32 §5). Native coupons are merchant discounts, never ShopBonus wallet value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_syncs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->string('kind', 16);                 // products | coupons
            $table->string('status', 16);               // running | completed | partial | failed
            $table->unsignedInteger('credential_generation');
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('items')->default(0);
            $table->unsignedInteger('removed')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'kind', 'id']);
        });

        Schema::create('connection_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->foreignId('webshop_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->string('sku', 128)->nullable();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->json('categories')->nullable();
            $table->unsignedBigInteger('price_minor')->nullable();
            $table->unsignedBigInteger('regular_price_minor')->nullable();
            $table->unsignedBigInteger('sale_price_minor')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('product_type', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('visibility', 32)->nullable();
            $table->string('stock_status', 32)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->unsignedBigInteger('last_sync_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
            $table->index(['webshop_id', 'removed_at']);
        });

        Schema::create('connection_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->foreignId('webshop_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->text('code');                       // encrypted
            $table->string('discount_type', 32);        // percent | fixed_cart | fixed_product
            $table->string('amount', 32);               // exact provider decimal string
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedInteger('percent_bps')->nullable();
            $table->char('currency', 3)->nullable();
            $table->unsignedBigInteger('minimum_spend_minor')->nullable();
            $table->unsignedBigInteger('maximum_spend_minor')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_customer')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('stackable');
            $table->boolean('exclude_sale_items')->default(false);
            $table->boolean('free_shipping')->default(false);
            $table->boolean('customer_restricted')->default(false);
            $table->json('scope')->nullable();          // product / category include and exclude IDs
            $table->string('status', 32)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->unsignedBigInteger('last_sync_id')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'external_id']);
            $table->index(['webshop_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_coupons');
        Schema::dropIfExists('connection_products');
        Schema::dropIfExists('catalog_syncs');
    }
};
