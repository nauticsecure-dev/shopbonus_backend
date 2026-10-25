<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->unsignedInteger('last_version')->default(0);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamps();
        });
        Schema::create('platform_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('setting_id')->constrained('platform_settings')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('value');
            $table->char('checksum', 64);
            $table->string('status', 16)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('rollback_of_id')->nullable();
            $table->string('reason', 500);
            $table->string('review_note', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['setting_id', 'version']);
            $table->foreign('rollback_of_id')->references('id')->on('platform_setting_versions')->restrictOnDelete();
        });
        Schema::table('platform_settings', fn (Blueprint $table) => $table->foreign('published_version_id')->references('id')->on('platform_setting_versions')->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('platform_settings', fn (Blueprint $table) => $table->dropForeign(['published_version_id']));
        Schema::dropIfExists('platform_setting_versions');
        Schema::dropIfExists('platform_settings');
    }
};
