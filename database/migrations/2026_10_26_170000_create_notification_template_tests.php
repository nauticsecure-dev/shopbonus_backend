<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_template_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->unique()->constrained('platform_setting_versions')->restrictOnDelete();
            $t->string('checksum', 64);
            $t->json('report');
            $t->foreignId('tested_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('notification_template_fallbacks', function (Blueprint $t) {
            $t->id();
            $t->string('event_type', 48);
            $t->string('requested_locale', 2);
            $t->unsignedBigInteger('occurrences')->default(0);
            $t->unique(['event_type', 'requested_locale'], 'notification_fallback_locale');
        });
        Schema::create('notification_ai_quotas', function (Blueprint $t) {
            $t->date('day')->primary();
            $t->unsignedInteger('requests')->default(0);
        });
        Schema::create('notification_template_generations', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('request_hash', 64);
            $t->string('status', 16);
            $t->string('error_code', 48)->nullable();
            $t->json('versions')->nullable();
            $t->timestamps();
            $t->unique(['created_by', 'request_key'], 'notification_ai_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_template_fallbacks');
        Schema::dropIfExists('notification_template_generations');
        Schema::dropIfExists('notification_ai_quotas');
        Schema::dropIfExists('notification_template_tests');
    }
};
