<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_provider_resources', fn (Blueprint $t) => $t->char('credential_hash', 64)->nullable());
        Schema::create('affiliate_promotion_reviews', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('offer_id')->constrained('offers')->restrictOnDelete();
            $t->foreignId('program_id')->constrained('affiliate_merchant_programs')->restrictOnDelete();
            $t->foreignId('offer_version_id')->constrained('offer_versions')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->json('evidence');
            $t->json('normalized');
            $t->string('status')->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->foreignId('published_version_id')->nullable()->constrained('offer_versions')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_promotion_reviews');
        Schema::table('affiliate_provider_resources', fn (Blueprint $t) => $t->dropColumn('credential_hash'));
    }
};
