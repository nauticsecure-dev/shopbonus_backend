<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_ai_suggestions', function (Blueprint $table) {
            $table->string('source_hash', 64)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
        });
        Schema::table('cms_revisions', function (Blueprint $table) {
            // Null on historical evidence means unverified, never assumed current.
            $table->unsignedInteger('source_master_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms_revisions', function (Blueprint $table) {
            $table->dropColumn('source_master_revision');
        });
        Schema::table('cms_ai_suggestions', function (Blueprint $table) {
            $table->dropColumn(['source_hash', 'model', 'input_tokens', 'output_tokens']);
        });
    }
};
