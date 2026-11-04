<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_ai_bulk_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('kind', 16);
            $table->string('source_locale', 5);
            $table->string('target_locale', 5);
            $table->string('status', 32)->default('planning');
            $table->unsignedBigInteger('source_cursor')->default(0);
            $table->unsignedBigInteger('reconcile_cursor')->default(0);
            $table->unsignedBigInteger('source_ceiling');
            $table->unsignedInteger('item_limit');
            $table->timestamps();
        });
        Schema::create('cms_ai_bulk_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('cms_ai_bulk_runs');
            $table->foreignId('page_id')->constrained('cms_pages');
            $table->unsignedInteger('source_revision');
            $table->char('source_hash', 64);
            $table->unsignedInteger('target_revision')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('error_code', 80)->nullable();
            $table->foreignId('suggestion_id')->nullable()->constrained('cms_ai_suggestions');
            $table->timestamps();
            $table->unique(['run_id', 'page_id']);
            $table->index(['run_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_bulk_items');
        Schema::dropIfExists('cms_ai_bulk_runs');
    }
};
