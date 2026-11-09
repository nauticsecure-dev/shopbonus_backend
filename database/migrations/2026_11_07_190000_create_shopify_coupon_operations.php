<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_coupon_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('webshop_connections')->restrictOnDelete();
            $table->foreignId('offer_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('credential_generation');
            $table->string('verified_domain');
            $table->boolean('enabled')->default(false);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('shopify_coupon_operations', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('offer_id')->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('webshop_connections')->restrictOnDelete();
            $table->foreignId('offer_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('credential_generation');
            $table->string('verified_domain');
            $table->string('request_key', 100);
            $table->text('code');
            $table->text('input');
            $table->string('state', 32)->default('prepared');
            $table->string('remote_id')->nullable();
            $table->foreignId('voucher_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['offer_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_coupon_operations');
        Schema::dropIfExists('shopify_coupon_programs');
    }
};
