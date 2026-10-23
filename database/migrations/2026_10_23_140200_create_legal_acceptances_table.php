<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable history of legal document acceptance (Terms, Privacy, later
 * merchant agreements). A new version is a new row; rows are never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 32);
            $table->string('document_version', 64);
            $table->timestamp('accepted_at', precision: 6);
            // Minimal evidence: request_id, ip_hash, locale. No raw IP or user agent.
            $table->json('evidence')->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->unique(['user_id', 'document_type', 'document_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
