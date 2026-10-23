<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned merchant agreements (Trello #16 "Merchant agreement", #3 A.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key', 64);              // e.g. merchant_agreement
            $table->string('version', 32);
            $table->string('locale', 5);
            $table->string('title');
            $table->longText('body');               // Markdown with {{placeholders}}
            $table->string('status', 16)->default('draft');
            $table->boolean('requires_reacceptance')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['key', 'locale', 'version']);
            $table->index(['key', 'locale', 'status']);
        });

        Schema::create('webshop_agreement_acceptances', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('agreement_template_id')->constrained()->restrictOnDelete();
            $table->string('version', 32);
            // Exact document the signatory saw, and its SHA-256: immutable evidence.
            $table->longText('rendered_body');
            $table->char('document_hash', 64);
            $table->string('signatory_name', 150);
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at', precision: 6);
            $table->json('evidence')->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['webshop_id', 'accepted_at']);
        });

        Schema::table('webshops', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('webshops', fn (Blueprint $table) => $table->dropColumn('rejected_at'));
        Schema::dropIfExists('webshop_agreement_acceptances');
        Schema::dropIfExists('agreement_templates');
    }
};
