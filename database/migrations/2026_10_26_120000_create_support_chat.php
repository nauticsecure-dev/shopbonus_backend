<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('webshop_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('locale', 2);
            $t->timestamp('expires_at')->index();
            $t->timestamps();
            $t->index(['user_id', 'id']);
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->string('status', 32)->default('PENDING');
            $t->text('question');
            $t->text('research');
            $t->longText('evidence')->nullable();
            $t->text('result')->nullable();
            $t->json('metrics')->nullable();
            $t->string('failure_code', 64)->nullable();
            $t->boolean('retry_used')->default(false);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['conversation_id', 'id']);
            $t->index(['status', 'created_at']);
        });
        Schema::create('support_feedback', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('rating', 4);
            $t->string('reason', 40)->nullable();
            $t->text('comment')->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'user_id']);
        });
        Schema::create('support_reviews', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $t->string('trigger', 40);
            $t->string('outcome', 32)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'trigger']);
            $t->index(['outcome', 'id']);
        });
        Schema::create('support_replies', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('support_regressions', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('review_id')->unique()->constrained('support_reviews')->cascadeOnDelete();
            $t->text('question');
            $t->string('expected_intent', 40);
            $t->foreignId('knowledge_id')->nullable()->constrained('faq_items')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('support_policy_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('version_id')->unique()->constrained('platform_setting_versions')->restrictOnDelete();
            $t->char('checksum', 64);
            $t->json('report');
            $t->foreignId('tested_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
        });
        Schema::create('support_quotas', function (Blueprint $t) {
            $t->date('day');
            $t->string('scope', 40);
            $t->unsignedInteger('requests')->default(0);
            $t->primary(['day', 'scope']);
        });
    }

    public function down(): void
    {
        foreach (['support_quotas', 'support_policy_tests', 'support_regressions', 'support_replies', 'support_reviews', 'support_feedback', 'support_messages', 'support_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
