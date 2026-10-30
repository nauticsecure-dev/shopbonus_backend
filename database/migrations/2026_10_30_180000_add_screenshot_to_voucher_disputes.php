<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional screenshot evidence on "Code Does Not Work" disputes.
 * Stored on the private local disk; served only through authorized API routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voucher_disputes', function (Blueprint $table) {
            $table->string('screenshot_path', 255)->nullable()->after('description');
            $table->string('screenshot_mime', 64)->nullable()->after('screenshot_path');
            $table->unsignedInteger('screenshot_bytes')->nullable()->after('screenshot_mime');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_disputes', function (Blueprint $table) {
            $table->dropColumn(['screenshot_path', 'screenshot_mime', 'screenshot_bytes']);
        });
    }
};
