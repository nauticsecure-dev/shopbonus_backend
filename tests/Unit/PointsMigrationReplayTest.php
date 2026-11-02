<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PointsMigrationReplayTest extends TestCase
{
    public function test_non_destructive_migrations_reapply_without_losing_contractual_points_terms(): void
    {
        $original = config('database.default');
        config(['database.default' => 'points_migration_probe', 'database.connections.points_migration_probe' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        try {
            foreach (['offer_versions', 'voucher_checkouts', 'affiliate_merchant_programs'] as $name) {
                Schema::create($name, fn (Blueprint $table) => $table->id());
            }
            Schema::create('affiliate_reward_rules', function (Blueprint $table) {
                $table->id();
                $table->boolean('active')->default(true);
            });
            $checkout = require database_path('migrations/2026_10_31_110000_add_separate_points_checkout_prices.php');
            $earning = require database_path('migrations/2026_10_31_120000_add_explicit_points_earning_rules.php');
            $checkout->up();
            $earning->up();
            DB::table('affiliate_merchant_programs')->insert(['id' => 1]);
            DB::table('offer_versions')->insert(['price_points' => 250]);
            DB::table('voucher_checkouts')->insert(['wallet_unit' => 'points', 'price_points' => 250, 'wallet_part_points' => 250]);
            DB::table('affiliate_reward_rules')->insert(['program_id' => 1, 'calculation_version' => 'points_v2', 'points_per_eur_share' => 10]);
            $earning->down();
            $checkout->down();
            $checkout->up();
            $earning->up();
            $this->assertSame(250, DB::table('offer_versions')->value('price_points'));
            $this->assertSame('points', DB::table('voucher_checkouts')->value('wallet_unit'));
            $this->assertSame(250, DB::table('voucher_checkouts')->value('wallet_part_points'));
            $this->assertSame(10, DB::table('affiliate_reward_rules')->value('points_per_eur_share'));
            $this->assertTrue(Schema::hasIndex('affiliate_reward_rules', 'reward_program_active_idx'));
        } finally {
            DB::purge('points_migration_probe');
            config(['database.default' => $original]);
        }
    }
}
