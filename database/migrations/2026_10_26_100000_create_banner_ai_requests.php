<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_ai_quotas', function (Blueprint $t) {
            $t->date('day')->primary();
            $t->unsignedInteger('requests')->default(0);
        });
        Schema::create('banner_ai_requests', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $t->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('kind', 16);
            $t->string('status', 16);
            $t->unsignedInteger('revision');
            $t->foreignId('source_offer_version_id')->nullable()->constrained('offer_versions')->restrictOnDelete();
            $t->string('model', 100);
            $t->json('context');
            $t->char('context_hash', 64);
            $t->json('source_config');
            $t->string('failure_code', 40)->nullable();
            $t->timestamps();
            $t->unique(['webshop_id', 'request_key']);
        });
        Schema::create('banner_ai_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->unique()->constrained('banner_ai_requests')->restrictOnDelete();
            $t->json('proposals');
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('banner_ai_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_id')->unique()->constrained('banner_ai_requests')->restrictOnDelete();
            $t->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->string('decision', 16);
            $t->unsignedTinyInteger('proposal')->nullable();
            $t->foreignId('draft_campaign_id')->nullable()->constrained('banner_campaigns')->restrictOnDelete();
            $t->string('note', 500);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['banner_ai_reviews', 'banner_ai_results', 'banner_ai_requests', 'banner_ai_quotas'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
