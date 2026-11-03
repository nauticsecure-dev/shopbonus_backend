<?php

namespace Tests\Unit\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SupportGuardMigrationTest extends TestCase
{
    public function test_existing_unknown_spending_is_closed_and_replay_preserves_reserved_exposure(): void
    {
        $original = config('database.default');
        config(['database.connections.support_migration' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'database.default' => 'support_migration']);
        try {
            Schema::create('support_quotas', function (Blueprint $t) {
                $t->date('day');
                $t->string('scope', 40);
                $t->unsignedInteger('requests')->default(0);
                $t->primary(['day', 'scope']);
            });
            DB::table('support_quotas')->insert(['day' => '2026-11-03', 'scope' => 'global', 'requests' => 1]);
            $migration = require database_path('migrations/2026_11_03_100000_add_support_execution_guards.php');
            $migration->up();
            $this->assertSame(1000000000, (int) DB::table('support_quotas')->where('scope', 'budget:eur')->value('reserved_micro_eur'));
            DB::table('support_quotas')->insert(['day' => '2026-11-04', 'scope' => 'budget:eur', 'requests' => 0, 'reserved_micro_eur' => 10240]);
            $migration->down();
            $migration->up();
            $this->assertSame(10240, (int) DB::table('support_quotas')->where('day', '2026-11-04')->value('reserved_micro_eur'));
            $this->assertSame(1, DB::table('support_quotas')->where('scope', 'global')->value('requests'));
        } finally {
            DB::purge('support_migration');
            config(['database.default' => $original]);
        }
    }
}
