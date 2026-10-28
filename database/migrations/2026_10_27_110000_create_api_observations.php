<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_incident_occurrences', fn (Blueprint $t) => $t->json('monitoring_evidence')->nullable());
        Schema::create('api_observations', function (Blueprint $t) {
            $t->id();
            $t->string('route', 190);
            $t->string('method', 8);
            $t->unsignedSmallInteger('status');
            $t->unsignedBigInteger('duration_us');
            $t->unsignedInteger('query_count');
            $t->unsignedBigInteger('query_us');
            $t->unsignedInteger('slow_queries');
            $t->timestamp('observed_at', 6);
            $t->index(['observed_at', 'id'], 'api_observed_window');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_observations');
        Schema::table('security_incident_occurrences', fn (Blueprint $t) => $t->dropColumn('monitoring_evidence'));
    }
};
