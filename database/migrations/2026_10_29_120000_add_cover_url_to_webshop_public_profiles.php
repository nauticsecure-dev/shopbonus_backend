<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional public cover image URL for discovery cards (directory lifestyle image). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webshop_public_profiles', function (Blueprint $table) {
            $table->string('cover_url', 500)->nullable()->after('logo_mime');
        });
    }

    public function down(): void
    {
        Schema::table('webshop_public_profiles', function (Blueprint $table) {
            $table->dropColumn('cover_url');
        });
    }
};
