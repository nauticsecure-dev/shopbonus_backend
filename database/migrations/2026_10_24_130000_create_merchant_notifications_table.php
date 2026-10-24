<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant notifications (Trello #8 §6): one store, deduplicated per
 * workspace, read state per workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_notifications', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);              // low_inventory | new_claims | offer_expiring | connector_error | low_funding
            $table->string('severity', 8);           // info | warning | critical
            $table->string('title', 160);
            $table->string('body', 500);
            $table->string('subject_type', 24)->nullable();   // offer | connection | funding
            $table->string('subject_ref', 40)->nullable();    // public ID of the subject
            $table->string('dedupe_key', 120);
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('read_by')->nullable();
            $table->timestamps();

            $table->unique(['webshop_id', 'dedupe_key']);
            $table->index(['webshop_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_notifications');
    }
};
