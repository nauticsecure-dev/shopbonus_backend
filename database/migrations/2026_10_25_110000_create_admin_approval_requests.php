<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_entry_id')->constrained('wallet_ledger_entries')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_entry_id')->nullable()->unique()->constrained('wallet_ledger_entries')->restrictOnDelete();
            $table->bigInteger('amount');
            $table->string('unit', 32);
            $table->string('reason', 500);
            $table->string('verification_note', 500);
            $table->string('review_note', 500)->nullable();
            $table->string('policy_version', 64)->default('dual-admin-v1');
            $table->string('status', 20)->default('pending');
            $table->string('request_key', 64);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['requested_by', 'request_key'], 'admin_approval_request_dedupe');
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_approval_requests');
    }
};
