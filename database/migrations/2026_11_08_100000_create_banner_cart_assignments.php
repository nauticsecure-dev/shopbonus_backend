<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_cart_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('credential_generation');
            $table->char('cart_hash', 64)->unique();
            $table->foreignId('assignment_id')->constrained('banner_assignments')->cascadeOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_cart_assignments');
    }
};
