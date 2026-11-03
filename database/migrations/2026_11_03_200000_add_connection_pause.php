<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshop_connections', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webshop_connections', fn (Blueprint $table) => $table->dropColumn('paused_at'));
    }
};
