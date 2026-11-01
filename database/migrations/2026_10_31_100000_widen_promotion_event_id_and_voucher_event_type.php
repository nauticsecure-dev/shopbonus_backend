<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL enforces column lengths that SQLite ignores. Promotion event ids are
 * deterministic keys such as `order_paid:<ulid>` (37+ chars, not UUIDs), and
 * voucher event types/statuses include `platform_validation_provider_check`
 * and `merchant_verification_required` (30+ chars).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_events', function (Blueprint $table) {
            $table->string('event_id', 80)->change();
        });
        Schema::table('voucher_events', function (Blueprint $table) {
            $table->string('type', 48)->change();
            $table->string('from_status', 48)->nullable()->change();
            $table->string('to_status', 48)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Not narrowed back: existing rows may not fit the old lengths.
    }
};
