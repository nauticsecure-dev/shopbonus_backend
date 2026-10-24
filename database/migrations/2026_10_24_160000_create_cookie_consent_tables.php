<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned cookie policy and consent records (Trello #5 "Cookie banner &
 * consent").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cookie_policies', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('version', 32)->unique();
            $table->string('status', 12);              // draft | published | retired
            $table->json('categories');
            $table->json('texts');                     // {nl: {...}, en: {...}}
            $table->boolean('requires_reconsent')->default(false);
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cookie_consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('consent_id');                // opaque, stored by the visitor's browser
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('policy_version', 32);
            $table->json('choices');                   // {necessary, preferences, analytics, marketing}
            $table->string('action', 16);              // accept_all | reject_optional | customize | withdraw
            $table->char('ip_hash', 64)->nullable();
            $table->timestamp('created_at', precision: 6)->nullable();

            $table->index(['consent_id', 'id']);
            $table->index(['policy_version', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cookie_consents');
        Schema::dropIfExists('cookie_policies');
    }
};
