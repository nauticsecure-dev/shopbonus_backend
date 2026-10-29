<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shopware app-system registrations (one per Shopware shop-id). Secrets and Admin API
        // credentials are encrypted; a shop is bound to at most one Shopbonus connection.
        Schema::create('shopware_app_shops', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('shop_id', 64)->unique();
            $table->string('shop_url', 255)->nullable();
            $table->string('pending_shop_url', 255)->nullable();
            $table->text('shop_secret')->nullable();
            $table->text('pending_shop_secret')->nullable();
            $table->text('previous_shop_secret')->nullable();
            $table->timestamp('secrets_rotated_at')->nullable();
            $table->text('api_credentials')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('webshop_connections')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopware_app_shops');
    }
};
