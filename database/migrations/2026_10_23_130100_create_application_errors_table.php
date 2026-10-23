<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Technical error store (unexpected exceptions, failed queue jobs), kept
 * separate from the security audit trail in audit_events. Messages and
 * context are redacted before insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_errors', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', precision: 6)->index();
            $table->string('request_id', 128)->nullable()->index();
            $table->string('source', 16); // http | queue | console
            // Groups occurrences of the same fault: sha1(class|file|line).
            $table->char('fingerprint', 40)->index();
            $table->string('exception_class', 255);
            $table->text('message');
            $table->string('location', 512)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->string('route', 255)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('webshop_id')->nullable();
            $table->string('job_name', 255)->nullable();
            $table->string('queue', 100)->nullable();
            $table->string('connection', 100)->nullable();
            $table->json('context')->nullable();

            $table->index(['source', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_errors');
    }
};
