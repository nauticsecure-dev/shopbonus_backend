<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_invoice_exports', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('invoice_id')->unique()->constrained('funding_invoices')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('administration_id', 32);
            $table->string('mapping_reference', 200);
            $table->string('phase', 32)->default('prepared');
            $table->char('request_hash', 64);
            $table->text('request_payload');
            $table->longText('source_snapshot');
            $table->unsignedBigInteger('expected_total_minor');
            $table->string('remote_id', 32)->nullable();
            $table->string('remote_state', 32)->nullable();
            $table->string('remote_updated_at', 32)->nullable();
            $table->char('remote_observation_hash', 64)->nullable();
            $table->unsignedBigInteger('unpaid_minor')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivery_acknowledged_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['administration_id', 'remote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_invoice_exports');
    }
};
