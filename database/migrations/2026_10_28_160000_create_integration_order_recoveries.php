<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', fn (Blueprint $table) => $table->string('source_method', 20)->nullable());
        Schema::create('integration_order_recoveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('connection_id')->constrained('webshop_connections')->cascadeOnDelete();
            $table->foreignId('webshop_id')->constrained('webshops')->cascadeOnDelete();
            $table->unsignedBigInteger('credential_generation');
            $table->string('verified_domain', 255);
            $table->string('external_order_id', 20);
            $table->string('request_key', 80);
            $table->string('status', 20)->default('pending');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'credential_generation', 'request_key'], 'recovery_request_identity');
            $table->index(['status', 'leased_until', 'id'], 'recovery_drain');
            $table->index(['connection_id', 'credential_generation', 'id'], 'recovery_gate_evidence');
        });
        $this->invalidateConnected();
    }

    public function down(): void
    {
        $this->invalidateConnected();
        Schema::dropIfExists('integration_order_recoveries');
        Schema::table('webhook_events', fn (Blueprint $table) => $table->dropColumn('source_method'));
        // Never restore an unproved historical Connected status.
    }

    private function invalidateConnected(): void
    {
        // Historical Connected does not prove the seven gates. Preserve other limitations.
        DB::table('webshop_connections')->where('status', 'connected')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $limits = json_decode($row->limitations ?? '[]', true);
                $limits = is_array($limits) ? $limits : [];
                $limits[] = 'connection_quality_gates_pending';
                DB::table('webshop_connections')->where('id', $row->id)->update([
                    'status' => 'limited', 'connected_at' => null,
                    'limitations' => json_encode(array_values(array_unique($limits)), JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }
};
