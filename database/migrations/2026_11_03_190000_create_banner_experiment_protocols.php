<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banner_experiment_protocols', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('campaign_id')->constrained('banner_campaigns')->restrictOnDelete();
            $t->unsignedBigInteger('active_campaign_id')->nullable()->unique();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->longText('protocol');
            $t->longText('measurement');
            $t->char('protocol_hash', 64);
            $t->timestamps();
        });
        Schema::table('banner_experiments', fn (Blueprint $t) => $t->foreignId('analysis_protocol_id')->nullable()->constrained('banner_experiment_protocols')->restrictOnDelete());
        Schema::create('banner_experiment_readouts', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('experiment_id')->unique()->constrained('banner_experiments')->restrictOnDelete();
            $t->foreignId('protocol_id')->constrained('banner_experiment_protocols')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->longText('result');
            $t->longText('source_snapshot');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banner_experiment_readouts');
        Schema::table('banner_experiments', fn (Blueprint $t) => $t->dropConstrainedForeignId('analysis_protocol_id'));
        Schema::dropIfExists('banner_experiment_protocols');
    }
};
