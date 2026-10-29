<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $t->unsignedBigInteger('credential_generation');
            $t->string('verified_domain');
            $t->string('provider_store_ref');
            $t->uuid('request_key');
            $t->string('since_at', 32);
            $t->string('until_at', 32);
            $t->string('cursor', 2048)->nullable();
            $t->string('status', 20)->default('pending');
            $t->boolean('discovery_complete')->default(false);
            $t->unsignedInteger('pages')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->timestamp('leased_until')->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->string('last_error_code', 80)->nullable();
            $t->timestamps();
            $t->unique(['connection_id', 'credential_generation', 'request_key'], 'sync_request_identity');
            $t->index(['status', 'leased_until', 'id'], 'sync_drain');
        });
        Schema::create('integration_sync_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('integration_sync_runs')->cascadeOnDelete();
            $t->string('cursor_hash', 64);
            $t->json('order_ids');
            $t->timestamp('created_at');
            $t->unique(['run_id', 'cursor_hash'], 'sync_page_identity');
        });
        Schema::table('integration_order_recoveries', function (Blueprint $t) {
            $t->string('purpose', 20)->default('verification');
            $t->foreignId('bulk_run_id')->nullable()->constrained('integration_sync_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('integration_order_recoveries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('bulk_run_id');
            $t->dropColumn('purpose');
        });
        Schema::dropIfExists('integration_sync_pages');
        Schema::dropIfExists('integration_sync_runs');
    }
};
