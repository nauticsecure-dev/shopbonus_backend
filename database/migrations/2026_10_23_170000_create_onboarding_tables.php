<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business details and ownership verification (Trello #16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshops', function (Blueprint $table) {
            $table->timestamp('ownership_verified_at')->nullable()->after('status_reason');
            $table->string('ownership_method', 32)->nullable()->after('ownership_verified_at');
            // Normalised domain proven by verification; one verified claim per domain.
            $table->string('verified_domain')->nullable()->after('ownership_method')->index();
            $table->timestamp('review_requested_at')->nullable()->after('verified_domain');
            $table->json('review_flags')->nullable()->after('review_requested_at');
        });

        Schema::create('webshop_business_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->unique()->constrained()->restrictOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('trading_name')->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('website_domain')->nullable()->index();
            $table->string('kvk_number', 16)->nullable();
            $table->string('vat_number', 20)->nullable();
            // Personal contact details: encrypted at rest.
            $table->text('contact_email')->nullable();
            $table->text('contact_phone')->nullable();
            $table->text('main_contact_name')->nullable();
            $table->string('address_line')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('preferred_language', 5)->default('nl');
            $table->string('google_place_id')->nullable();
            $table->string('business_category', 100)->nullable();
            $table->string('source', 16)->default('manual'); // manual | google
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('webshop_verifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('method', 32);
            $table->string('status', 16);
            $table->string('domain')->nullable();
            // DNS/file: the public value the owner publishes. Not a secret.
            $table->string('challenge_value', 100)->nullable();
            // Domain email: target mailbox and HMAC of the emailed code.
            $table->string('email')->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            // Manual review: notes and evidence file references, encrypted.
            $table->text('evidence')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['webshop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webshop_verifications');
        Schema::dropIfExists('webshop_business_profiles');

        Schema::table('webshops', function (Blueprint $table) {
            $table->dropIndex(['verified_domain']);
            $table->dropColumn(['ownership_verified_at', 'ownership_method', 'verified_domain', 'review_requested_at', 'review_flags']);
        });
    }
};
