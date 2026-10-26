<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->string('event_type', 48);
            $table->string('event_key', 120);
            $table->string('recipient_type', 16);
            $table->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['notifiable_type', 'notifiable_id', 'event_key'], 'notification_recipient_event');
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notification_inbox');
        });
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('email_enabled')->default(true);
            $table->json('preferences');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
