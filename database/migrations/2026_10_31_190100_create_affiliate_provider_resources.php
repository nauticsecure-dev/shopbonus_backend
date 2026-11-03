<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_provider_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('affiliate_network_connections')->cascadeOnDelete();
            $table->string('resource_key', 180);
            $table->json('payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('last_error', 100)->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'resource_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_provider_resources');
    }
};
