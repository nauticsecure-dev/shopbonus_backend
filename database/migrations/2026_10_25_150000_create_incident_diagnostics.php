<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_diagnostic_quotas', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('requests')->default(0);
        });
        Schema::create('incident_diagnostics', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('incident_id')->constrained('security_incidents')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('request_key', 64);
            $table->string('status', 16)->default('queued');
            $table->json('context');
            $table->char('context_hash', 64);
            $table->string('model', 100);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['requested_by', 'request_key'], 'incident_diagnostic_dedupe');
            $table->index(['incident_id', 'id']);
        });
        Schema::create('incident_diagnostic_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagnostic_id')->unique()->constrained('incident_diagnostics')->restrictOnDelete();
            $table->json('analysis');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('incident_diagnostic_reviews', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('diagnostic_id')->constrained('incident_diagnostics')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('note', 500);
            $table->timestamp('created_at');
            $table->unique(['diagnostic_id', 'reviewed_by'], 'diagnostic_reviewer_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_diagnostic_reviews');
        Schema::dropIfExists('incident_diagnostic_results');
        Schema::dropIfExists('incident_diagnostics');
        Schema::dropIfExists('incident_diagnostic_quotas');
    }
};
