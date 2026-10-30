<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shopify mandatory privacy requests (app-level, not per connection). The signed body is
        // stored encrypted as evidence; nothing is erased automatically (D-099).
        Schema::create('shopify_compliance_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('webhook_id', 100)->unique();
            $table->string('event_id', 100)->nullable();
            $table->string('topic', 32);
            $table->string('shop_domain', 255)->nullable()->index();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->boolean('payload_valid');
            $table->text('payload');
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('delivery_count')->default(1);
            $table->timestamp('received_at');
            $table->timestamp('due_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_compliance_requests');
    }
};
