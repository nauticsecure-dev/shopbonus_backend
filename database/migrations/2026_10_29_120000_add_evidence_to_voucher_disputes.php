<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screenshot evidence on "Code does not work" reports (Trello #23). Only
 * metadata lives here; the encrypted file is in private storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_disputes', function (Blueprint $table) {
            $table->json('evidence')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_disputes', fn (Blueprint $table) => $table->dropColumn('evidence'));
    }
};
