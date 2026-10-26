<?php

namespace Tests\Feature\Notifications;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationMigrationTest extends TestCase
{
    public function test_new_notification_migrations_can_rollback_and_reapply_with_existing_base_schema(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
        $files = ['2026_10_26_150000_create_notification_outbox.php', '2026_10_26_160000_create_notification_deliveries.php', '2026_10_26_170000_create_notification_template_tests.php'];
        foreach (array_reverse($files) as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->down();
        }
        $this->assertFalse(Schema::hasTable('notification_legacy_reads'));
        $this->assertFalse(Schema::hasTable('notification_deliveries'));
        $this->assertFalse(Schema::hasColumn('notifications', 'in_app_enabled'));
        foreach ($files as $file) {
            $migration = require database_path('migrations/'.$file);
            $migration->up();
        }
        $this->assertTrue(Schema::hasTable('notification_legacy_reads'));
        $this->assertTrue(Schema::hasTable('notification_deliveries'));
        $this->assertTrue(Schema::hasColumn('notifications', 'in_app_enabled'));
        $this->assertTrue(Schema::hasTable('notification_template_fallbacks'));
    }
}
