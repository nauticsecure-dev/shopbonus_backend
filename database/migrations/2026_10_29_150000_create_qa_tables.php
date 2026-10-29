<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality Center backend (Trello #37): discovered inventory per Git SHA,
 * approved test cases and coverage, imported runs/results, private
 * evidence, findings with retest history and schedules. Executable tests stay
 * in Git; this database holds inventory and results only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('git_sha', 64);
            $table->json('totals');
            $table->json('diff');                       // {added, changed, removed, undocumented} keyed by kind
            $table->unsignedBigInteger('created_by')->nullable(); // null = deploy command
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });

        Schema::create('qa_endpoints', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->string('name', 150)->nullable();
            $table->string('action', 255)->nullable();
            $table->string('summary', 255)->nullable();  // OpenAPI summary ("purpose")
            $table->string('access', 16);
            $table->string('permission', 60)->nullable();
            $table->string('scope', 40);
            $table->boolean('documented');
            $table->string('fingerprint', 64);
            $table->string('first_seen_sha', 64);
            $table->string('changed_sha', 64);          // SHA where the current fingerprint first appeared
            $table->timestamp('changed_at');
            $table->timestamp('removed_at')->nullable();
            $table->boolean('frontend_referenced')->nullable(); // null = no frontend usage report yet
            $table->timestamps();

            $table->unique(['method', 'path']);
        });

        Schema::create('qa_components', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('kind', 16);                 // job | event | listener | command | schedule | migration | connector
            $table->string('name', 255);
            $table->string('fingerprint', 64);
            $table->string('first_seen_sha', 64);
            $table->string('changed_sha', 64);
            $table->timestamp('changed_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'name']);
        });

        Schema::create('qa_test_cases', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 255)->unique();       // JUnit classname::name, or a manual key such as E2E-07
            $table->string('title', 255);
            $table->string('scenario', 16)->nullable(); // PDF scenario 01–25 or acceptance E2E-01–10
            $table->string('source', 16);               // phpunit | postman | browser | manual
            $table->string('status', 12);               // draft | approved | retired
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('qa_test_coverage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_case_id')->constrained('qa_test_cases')->cascadeOnDelete();
            $table->string('target_type', 12);          // endpoint | component
            $table->unsignedBigInteger('target_id');
            $table->timestamps();

            $table->unique(['test_case_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('qa_schedules', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 100);
            $table->string('suite', 16);                // safe | changed | full | weekly
            $table->string('environment', 16);          // production | staging
            $table->string('trigger', 16);              // every_5_minutes | after_deploy | nightly | weekly
            $table->unsignedInteger('timeout_minutes')->default(60);
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('qa_test_runs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('schedule_id')->nullable()->constrained('qa_schedules')->nullOnDelete();
            $table->string('suite', 16);
            $table->string('environment', 16);
            $table->string('git_sha', 64);
            $table->string('source', 16);               // junit | probe
            $table->string('report_hash', 64)->unique(); // the same report is never imported twice
            $table->json('totals');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['environment', 'created_at']);
        });

        Schema::create('qa_test_results', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('run_id')->constrained('qa_test_runs')->cascadeOnDelete();
            $table->foreignId('test_case_id')->nullable()->constrained('qa_test_cases')->nullOnDelete();
            $table->string('key', 255);
            $table->string('status', 16);               // pass | fail | blocked | not_implemented | skipped
            $table->unsignedInteger('assertions')->default(0);
            $table->boolean('mocked')->default(false);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('message')->nullable();        // redacted
            $table->timestamp('created_at')->nullable();

            $table->index(['test_case_id', 'id']);
            $table->index(['run_id', 'status']);
        });

        Schema::create('qa_evidence', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('run_id')->constrained('qa_test_runs')->restrictOnDelete();
            $table->foreignId('result_id')->nullable()->constrained('qa_test_results')->nullOnDelete();
            $table->string('path', 255);
            $table->string('name', 120);
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->string('sha256', 64);
            $table->string('request_id', 64)->nullable();
            $table->timestamp('retain_until');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();

            $table->unique(['run_id', 'sha256']);
        });

        Schema::create('qa_findings', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('fingerprint', 64);          // severity-independent dedupe key of the defect
            $table->string('title', 200);
            $table->string('severity', 4);              // p0 | p1 | p2 | p3
            $table->string('status', 16);               // open | fixed | retest_passed | retest_failed
            $table->text('reproduction')->nullable();
            $table->json('affected_routes');
            $table->json('test_keys');
            $table->foreignId('run_id')->nullable()->constrained('qa_test_runs')->nullOnDelete();
            $table->unsignedBigInteger('owner_id')->nullable();   // developer assigned to fix
            $table->string('fix_commit', 64)->nullable();
            $table->unsignedBigInteger('fixed_by')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->foreignId('retest_run_id')->nullable()->constrained('qa_test_runs')->nullOnDelete();
            $table->unsignedBigInteger('retested_by')->nullable();
            $table->json('ai_analysis')->nullable();
            $table->string('ai_status', 12)->nullable(); // processing | proposed | failed
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['fingerprint', 'status']);
            $table->index(['owner_id', 'status']);
        });

        Schema::create('qa_ai_quotas', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('requests')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['qa_ai_quotas', 'qa_findings', 'qa_evidence', 'qa_test_results', 'qa_test_runs', 'qa_schedules', 'qa_test_coverage', 'qa_test_cases', 'qa_components', 'qa_endpoints', 'qa_inventory_snapshots'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
