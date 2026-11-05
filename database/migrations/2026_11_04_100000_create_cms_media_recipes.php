<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_assets', function (Blueprint $t) {
            $t->string('original_path')->nullable();
            $t->char('original_hash', 64)->nullable();
            $t->string('original_mime')->nullable();
            $t->unsignedInteger('media_revision')->default(0);
            $t->unsignedBigInteger('active_recipe_id')->nullable();
        });
        Schema::create('cms_media_recipes', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('asset_id')->constrained('cms_assets')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->unique(['asset_id', 'version']);
            $t->char('original_hash', 64);
            $t->json('recipe');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('cms_media_derivatives', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained('cms_media_recipes')->restrictOnDelete();
            $t->string('breakpoint', 20);
            $t->unique(['recipe_id', 'breakpoint']);
            $t->string('status')->default('queued');
            $t->string('path')->nullable();
            $t->char('content_hash', 64)->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('failure_code')->nullable();
            $t->json('renderer')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media_derivatives');
        Schema::dropIfExists('cms_media_recipes');
        Schema::table('cms_assets', fn (Blueprint $t) => $t->dropColumn(['original_path', 'original_hash', 'original_mime', 'media_revision', 'active_recipe_id']));
    }
};
