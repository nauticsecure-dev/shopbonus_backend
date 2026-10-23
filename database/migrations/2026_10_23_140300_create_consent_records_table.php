<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only optional-consent history (e.g. marketing e-mail). The current
 * state of a purpose is its latest row. Mandatory account/security mail is
 * not consent-based and never recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 64);
            $table->boolean('granted');
            // Version of the consent wording shown to the user.
            $table->string('policy_version', 64);
            $table->string('source', 32); // registration | preferences | admin ...
            $table->timestamp('recorded_at', precision: 6);
            $table->json('evidence')->nullable();

            $table->index(['user_id', 'purpose', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_records');
    }
};
