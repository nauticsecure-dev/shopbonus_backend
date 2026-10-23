<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consumer profile, preferences and privacy requests (Trello #10, #18
 * "Profile & settings").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('phone')->nullable()->after('email');            // encrypted E.164
            $table->string('avatar_path', 255)->nullable()->after('locale');  // private disk
            $table->string('avatar_mime', 32)->nullable()->after('avatar_path');
        });

        Schema::create('email_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('new_email');
            $table->char('code_hash', 64);          // HMAC; the code is never stored
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'consumed_at', 'superseded_at'], 'ecr_user_open_index');
        });

        Schema::create('consumer_favorite_webshops', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webshop_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->primary(['user_id', 'webshop_id']);
        });

        Schema::create('consumer_category_preferences', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);

            $table->primary(['user_id', 'category']);
        });

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);             // deletion
            $table->string('status', 16);           // open | completed | cancelled
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_requests');
        Schema::dropIfExists('consumer_category_preferences');
        Schema::dropIfExists('consumer_favorite_webshops');
        Schema::dropIfExists('email_change_requests');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['phone', 'avatar_path', 'avatar_mime']));
    }
};
