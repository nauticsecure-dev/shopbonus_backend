<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived click references and marketing-consent evidence on tracked visits
 * (Trello #19 "Tracked consumer journey").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('created_at');
            $table->char('consent_id_hash', 64)->nullable()->after('ip_hash');
            $table->boolean('marketing_consent')->default(false)->after('consent_id_hash');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_clicks', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'consent_id_hash', 'marketing_consent']);
        });
    }
};
