<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_enrichment_quotas', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('runs')->default(0);
        });

        Schema::create('merchant_enrichment_runs', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('webshop_id')->constrained('webshops')->restrictOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('trigger', 16)->default('scheduled');
            $table->string('source_url', 500);
            $table->json('research');
            $table->char('research_hash', 64);
            $table->string('text_model', 100);
            $table->string('image_model', 100)->nullable();
            $table->json('proposal')->nullable();
            $table->boolean('review_required')->default(true);
            $table->string('risk_level', 16)->nullable();
            $table->string('cover_path', 500)->nullable();
            $table->string('cover_mime', 64)->nullable();
            $table->string('cover_failure_code', 64)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['webshop_id', 'id']);
            $table->index(['status', 'id']);
        });

        Schema::create('merchant_enrichment_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('run_id')->constrained('merchant_enrichment_runs')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('note', 500);
            $table->timestamp('created_at');
            $table->unique(['run_id', 'reviewed_by'], 'merchant_enrichment_reviewer_once');
        });

        Schema::table('webshop_public_profiles', function (Blueprint $table) {
            $table->string('cover_path', 500)->nullable()->after('cover_url');
            $table->string('cover_mime', 64)->nullable()->after('cover_path');
        });
    }

    public function down(): void
    {
        Schema::table('webshop_public_profiles', function (Blueprint $table) {
            $table->dropColumn(['cover_path', 'cover_mime']);
        });
        Schema::dropIfExists('merchant_enrichment_reviews');
        Schema::dropIfExists('merchant_enrichment_runs');
        Schema::dropIfExists('merchant_enrichment_quotas');
    }
};
