<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_top_ups', function (Blueprint $t) {
            $t->uuid('request_key')->nullable();
            $t->uuid('provider_idempotency_key')->nullable();
            $t->string('creation_state', 16)->default('created');
            $t->timestamp('creation_attempted_at')->nullable();
            $t->unique(['webshop_id', 'created_by', 'request_key'], 'funding_request_once');
        });
        DB::table('funding_top_ups')->whereNull('provider_ref')->update(['creation_state' => 'uncertain']);
    }

    public function down(): void
    {
        // InnoDB may replace the original implicit FK index with our composite
        // index. Restore it before dropping that index during rollback.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && ! Schema::hasIndex('funding_top_ups', 'funding_top_ups_webshop_id_foreign')) {
            Schema::table('funding_top_ups', fn (Blueprint $t) => $t->index('webshop_id', 'funding_top_ups_webshop_id_foreign'));
        }

        Schema::table('funding_top_ups', function (Blueprint $t) {
            $t->dropUnique('funding_request_once');
            $t->dropColumn(['request_key', 'provider_idempotency_key', 'creation_state', 'creation_attempted_at']);
        });
    }
};
