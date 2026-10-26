<?php

use App\Services\Admin\PlatformSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_knowledge_versions', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('faq_id')->constrained('faq_items')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->json('snapshot');
            $t->char('checksum', 64);
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('origin', 16);
            $t->timestamp('created_at');
            $t->unique(['faq_id', 'revision']);
        });
        // Existing publication times/approvers are unknown. Record a backfill snapshot, never an invented approval.
        DB::table('faq_items')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $faq) {
                $snapshot = ['audience' => $faq->audience, 'published' => (bool) $faq->published, 'translations' => json_decode($faq->translations, true, 512, JSON_THROW_ON_ERROR)];
                DB::table('support_knowledge_versions')->insert(['public_id' => strtolower((string) Str::ulid()), 'faq_id' => $faq->id, 'revision' => $faq->revision,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'checksum' => PlatformSettings::checksum($snapshot), 'recorded_by' => null, 'origin' => 'backfill', 'created_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_knowledge_versions');
    }
};
