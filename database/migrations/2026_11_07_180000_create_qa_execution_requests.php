<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_execution_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_key');
            $table->string('deployment_sha', 64);
            $table->string('trigger', 20);
            $table->string('status', 24)->default('queued');
            $table->string('failure_code', 80)->nullable();
            $table->boolean('evidence_complete')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_execution_requests');
    }
};
