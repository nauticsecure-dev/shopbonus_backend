<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_observations', function (Blueprint $t) {
            $t->id();
            $t->string('component', 40);
            $t->json('metrics');
            $t->timestamp('observed_at');
            $t->index(['component', 'observed_at'], 'ops_component_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_observations');
    }
};
