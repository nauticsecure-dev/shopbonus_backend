<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_shared_blocks', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 80)->unique();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('cms_shared_block_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('block_id')->constrained('cms_shared_blocks');
            $table->unsignedInteger('number');
            $table->string('locale', 2);
            $table->json('node');
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('created_at');
            $table->unique(['block_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_shared_block_versions');
        Schema::dropIfExists('cms_shared_blocks');
    }
};
