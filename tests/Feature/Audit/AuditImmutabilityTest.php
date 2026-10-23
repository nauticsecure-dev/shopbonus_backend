<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private function event(): AuditEvent
    {
        return $this->app->make(AuditLogger::class)->record('auth.forbidden');
    }

    public function test_model_refuses_update(): void
    {
        $event = $this->event();

        $this->expectException(LogicException::class);
        $event->update(['action' => 'tampered']);
    }

    public function test_model_refuses_delete(): void
    {
        $event = $this->event();

        try {
            $event->delete();
            $this->fail('Delete should have been refused.');
        } catch (LogicException) {
            $this->assertSame(1, AuditEvent::count());
        }
    }

    public function test_database_trigger_refuses_update_that_bypasses_the_model(): void
    {
        $event = $this->event();

        try {
            DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']);
            $this->fail('Raw update should have been refused by the trigger.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->assertSame('auth.forbidden', DB::table('audit_events')->value('action'));
    }
}
