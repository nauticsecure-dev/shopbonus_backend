<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_experiments', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $t->unsignedBigInteger('active_campaign_id')->nullable()->unique();
            $t->foreignId('control_version_id')->nullable()->constrained('banner_versions')->restrictOnDelete();
            $t->foreignId('variant_version_id')->constrained('banner_versions')->restrictOnDelete();
            $t->unsignedSmallInteger('variant_bps');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('started_at');
            $t->timestamp('stopped_at')->nullable();
        });
        Schema::create('banner_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('experiment_id')->constrained('banner_experiments')->restrictOnDelete();
            $t->char('visitor_hash', 64);
            $t->char('token_hash', 64)->unique();
            $t->string('arm', 8);
            $t->timestamp('created_at');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->unique(['experiment_id', 'visitor_hash']);
        });
        foreach (['banner_events', 'banner_clicks', 'webshop_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('banner_assignment_id')->nullable()->constrained('banner_assignments')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['webshop_orders', 'banner_clicks', 'banner_events'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('banner_assignment_id'));
        }
        Schema::dropIfExists('banner_assignments');
        Schema::dropIfExists('banner_experiments');
    }
};
