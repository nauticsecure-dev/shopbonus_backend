<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banner campaigns on the merchant's own website (Trello #25) and their
 * measurement (Trello #24 §1–2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_installations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webshop_id')->unique()->constrained()->restrictOnDelete();
            $table->string('public_key', 40)->unique();     // pk_..., public by design
            $table->json('allowed_origins');
            $table->string('last_seen_origin', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('banner_campaigns', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('status', 16);                   // draft | scheduled | live | paused
            $table->json('draft_config');
            $table->unsignedInteger('draft_revision')->default(1);
            $table->unsignedBigInteger('published_version_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['webshop_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('banner_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('config');
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['campaign_id', 'version']);
        });

        Schema::create('banner_assets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->string('path', 255);
            $table->string('mime', 32);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('uploaded_by');
            $table->timestamps();
        });

        Schema::create('banner_pairings', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('origin', 255);
            $table->string('kind', 8);                      // pairing | editor
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('banner_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();             // client-generated; retries are ignored
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('type', 24);                     // eligible | viewable_impression | cta_click | dismiss
            $table->string('device', 8)->nullable();
            $table->string('path', 255)->nullable();        // no query string
            $table->string('category', 40)->nullable();
            $table->boolean('consent');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['campaign_id', 'type', 'occurred_at']);
            $table->index('created_at');
        });

        Schema::create('banner_clicks', function (Blueprint $table) {
            $table->id();
            $table->string('click_id', 40)->unique();       // opaque, only with consent
            $table->foreignId('webshop_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('banner_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $table->date('day');                            // business timezone
            $table->string('device', 8);
            $table->unsignedInteger('eligible')->default(0);
            $table->unsignedInteger('viewable_impressions')->default(0);
            $table->unsignedInteger('cta_clicks')->default(0);
            $table->unsignedInteger('dismissals')->default(0);

            $table->unique(['campaign_id', 'day', 'device']);
        });

        Schema::table('webshop_orders', function (Blueprint $table) {
            $table->string('attribution_click_id', 40)->nullable()->after('contact_consent')->index();
        });
    }

    public function down(): void
    {
        Schema::table('webshop_orders', function (Blueprint $table) {
            $table->dropIndex(['attribution_click_id']);
            $table->dropColumn('attribution_click_id');
        });
        foreach (['banner_daily_stats', 'banner_clicks', 'banner_events', 'banner_pairings', 'banner_assets', 'banner_versions', 'banner_campaigns', 'banner_installations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
