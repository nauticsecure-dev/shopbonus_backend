<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_authorizations', function (Blueprint $table) {
            $table->id();
            $table->string('state_hash', 64)->unique();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('session_hash', 64);
            $table->string('config_hash', 64);
            $table->unsignedBigInteger('credential_generation');
            $table->string('credential_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_authorizations');
    }
};
