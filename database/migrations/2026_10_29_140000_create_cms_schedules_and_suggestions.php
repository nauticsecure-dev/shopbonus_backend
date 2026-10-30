<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trello #33/#34/#35: scheduled publication/archiving of CMS pages and
 * human-reviewed AI text suggestions. Suggestions never publish; a schedule
 * only publishes the exact draft revision that was scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_publication_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('page_id')->constrained('cms_pages')->restrictOnDelete();
            $table->string('action', 12);                    // publish | archive
            $table->string('locale', 5)->nullable();         // publish only
            $table->unsignedInteger('draft_revision')->nullable();
            $table->json('legal')->nullable();               // {legal_version, effective_date} for legal pages
            $table->timestamp('due_at');
            $table->string('status', 12)->default('scheduled'); // scheduled | done | cancelled | stale | failed
            $table->string('outcome_code', 60)->nullable();
            $table->foreignId('revision_id')->nullable()->constrained('cms_revisions')->restrictOnDelete();
            $table->unsignedBigInteger('scheduled_by');
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_at']);
            $table->index(['page_id', 'status']);
        });

        Schema::create('cms_ai_suggestions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('page_id')->constrained('cms_pages')->restrictOnDelete();
            $table->string('kind', 12);                      // improve | translate
            $table->string('source_locale', 5);
            $table->string('target_locale', 5);
            $table->unsignedInteger('source_revision');
            $table->unsignedInteger('target_revision')->nullable(); // null = target translation did not exist yet
            $table->string('request_key', 100);
            $table->string('request_hash', 64);
            $table->string('status', 12);                    // processing | proposed | applied | rejected | failed
            $table->json('proposed')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'request_key']);
            $table->index(['page_id', 'status']);
        });

        Schema::create('cms_ai_quotas', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('requests')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_quotas');
        Schema::dropIfExists('cms_ai_suggestions');
        Schema::dropIfExists('cms_publication_schedules');
    }
};
