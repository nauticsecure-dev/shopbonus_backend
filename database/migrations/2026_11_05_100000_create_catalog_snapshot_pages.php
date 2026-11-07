<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_snapshot_pages')) {
            return;
        }
        // Signed catalog snapshot pages pushed by a merchant extension (OpenCart); read back by the catalog sync.
        Schema::create('catalog_snapshot_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->unsignedInteger('credential_generation');
            $table->string('kind', 16);
            $table->char('snapshot_id', 32);
            $table->unsignedSmallInteger('page');
            $table->boolean('last')->default(false);
            $table->char('currency', 3);
            $table->longText('items');
            $table->timestamp('received_at');
            $table->unique(['connection_id', 'kind', 'snapshot_id', 'page'], 'catalog_snapshot_pages_unique');
            $table->index(['connection_id', 'kind', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_snapshot_pages');
    }
};
