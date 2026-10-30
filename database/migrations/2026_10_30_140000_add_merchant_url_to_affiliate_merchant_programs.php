<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_merchant_programs', function (Blueprint $table) {
            $table->string('merchant_url', 1000)->nullable()->after('domain');
            $table->json('raw_evidence')->nullable()->after('tracking_url_template');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_merchant_programs', function (Blueprint $table) {
            $table->dropColumn(['merchant_url', 'raw_evidence']);
        });
    }
};
