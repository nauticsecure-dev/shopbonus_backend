<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee invitations (Trello #16 "Team management"): single use,
 * expiring, bound to one webshop and one recipient email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webshop_invitations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->json('permissions')->nullable();
            // HMAC-SHA256 of the emailed token; the token itself is never stored.
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at');
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['webshop_id', 'email', 'status']);
            $table->index(['webshop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webshop_invitations');
    }
};
