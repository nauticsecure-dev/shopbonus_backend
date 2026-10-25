<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->char('fingerprint', 64)->unique();
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('service', 100);
            $table->string('route')->nullable();
            $table->string('http_method', 10)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('status', 16)->default('new');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });
        Schema::create('security_incident_occurrences', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('incident_id')->constrained('security_incidents')->restrictOnDelete();
            $table->string('request_id', 128)->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('webshop_id')->nullable()->index();
            $table->char('scope_public_id', 26)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_occurrences');
        Schema::dropIfExists('security_incidents');
    }
};
