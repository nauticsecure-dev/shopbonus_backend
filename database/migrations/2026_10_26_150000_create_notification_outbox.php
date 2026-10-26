<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->string('event_key', 150)->unique();
            $t->string('event_type', 48);
            $t->string('recipient_type', 16);
            $t->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete();
            $t->text('payload');
            $t->string('status', 16)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('error_code', 48)->nullable();
            $t->timestamp('available_at');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
        });
        Schema::create('notification_legacy_reads', function (Blueprint $t) {
            $t->foreignId('legacy_notification_id')->constrained('merchant_notifications')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamp('read_at');
            $t->primary(['legacy_notification_id', 'user_id'], 'notification_legacy_reader');
        });
        Schema::table('notifications', function (Blueprint $t) {
            $t->unsignedBigInteger('legacy_notification_id')->nullable()->index();
            $t->boolean('in_app_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropIndex(['legacy_notification_id']);
            $t->dropColumn('legacy_notification_id');
            $t->dropColumn('in_app_enabled');
        });
        Schema::dropIfExists('notification_legacy_reads');
        Schema::dropIfExists('notification_events');
    }
};
