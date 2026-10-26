<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->uuid('notification_id');
            $t->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $t->string('channel', 16)->default('mail');
            $t->string('status', 16)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->uuid('claim_token')->nullable();
            $t->timestamp('claimed_at')->nullable();
            $t->timestamp('available_at');
            $t->timestamp('sent_at')->nullable();
            $t->string('error_code', 48)->nullable();
            $t->timestamps();
            $t->unique(['notification_id', 'channel']);
            $t->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
