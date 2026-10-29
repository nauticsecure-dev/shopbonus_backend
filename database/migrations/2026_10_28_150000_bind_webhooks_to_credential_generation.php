<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshop_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('credential_generation')->default(1);
        });
        Schema::table('webhook_events', function (Blueprint $table) {
            // Existing events have no provable credential binding; do not invent one.
            $table->unsignedBigInteger('credential_generation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', fn (Blueprint $table) => $table->dropColumn('credential_generation'));
        Schema::table('webshop_connections', fn (Blueprint $table) => $table->dropColumn('credential_generation'));
    }
};
