<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No backfill: old requests did not record the exact native store reference.
        Schema::table('integration_order_recoveries', fn (Blueprint $table) => $table->string('provider_store_ref', 255)->nullable());
    }

    public function down(): void
    {
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
        Schema::table('integration_order_recoveries', fn (Blueprint $table) => $table->dropColumn('provider_store_ref'));
    }
};
