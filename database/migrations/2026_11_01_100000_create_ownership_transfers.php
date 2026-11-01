<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ownership_transfers')) {
            return;
        }
        Schema::create('ownership_transfers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('pending'); // pending, accepted, declined, cancelled, expired
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['webshop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownership_transfers');
    }
};
