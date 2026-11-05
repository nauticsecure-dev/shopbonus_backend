<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_ai_quotas', function (Blueprint $table) {
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->boolean('spend_blocked')->default(false);
        });
        Schema::table('cms_ai_suggestions', function (Blueprint $table) {
            $table->json('context')->nullable();
            $table->date('budget_day')->nullable();
            $table->unsignedBigInteger('reserved_micros')->nullable();
            $table->unsignedBigInteger('estimated_micros')->nullable();
            $table->json('pricing_snapshot')->nullable();
        });
        Schema::create('cms_ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suggestion_id')->unique()->constrained('cms_ai_suggestions');
            $table->foreignId('reviewed_by')->constrained('users');
            $table->char('proposal_hash', 64);
            $table->char('source_hash', 64);
            $table->string('locale', 5);
            $table->boolean('accepted');
            $table->text('note');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_reviews');
        Schema::table('cms_ai_suggestions', fn (Blueprint $table) => $table->dropColumn(['context', 'budget_day', 'reserved_micros', 'estimated_micros', 'pricing_snapshot']));
        Schema::table('cms_ai_quotas', fn (Blueprint $table) => $table->dropColumn(['reserved_micros', 'spend_blocked']));
    }
};
