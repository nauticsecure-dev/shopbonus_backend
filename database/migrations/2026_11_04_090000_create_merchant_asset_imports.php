<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_asset_imports', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('run_id')->constrained('merchant_enrichment_runs')->restrictOnDelete();
            $t->string('evidence_key', 40);
            $t->unique(['run_id', 'evidence_key']);
            $t->foreignId('webshop_id')->constrained('webshops')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('source_url');
            $t->char('research_hash', 64);
            $t->char('content_hash', 64);
            $t->string('path');
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->string('status')->default('pending');
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('review_note')->nullable();
            $t->text('rights_reference')->nullable();
            $t->foreignId('banner_asset_id')->nullable()->constrained('banner_assets')->restrictOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_asset_imports');
    }
};
