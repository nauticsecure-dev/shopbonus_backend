<?php

namespace Tests\Feature\Audit;

use App\Models\AuditEvent;
use App\Services\Audit\AuditIntegrity;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_chain_matches_event_content_and_commits_atomically(): void
    {
        $audit = app(AuditLogger::class);
        $audit->record('fixture.first', metadata: ['z' => 10, 'a' => ['second' => true, 'first' => false]]);
        $audit->record('fixture.second');
        $this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
        $this->artisan('shopbonus:audit-verify')->assertSuccessful();
        try {
            DB::transaction(function () use ($audit) {
                $audit->record('rolled.back');
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(2, AuditEvent::count());
        $this->assertSame(2, DB::table('audit_event_seals')->count());
        $this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
    }

    public function test_verification_detects_event_tampering_and_unsealed_rows(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Destructive trigger-bypass simulation uses transactional SQLite DDL; MySQL trigger enforcement is tested separately.');
        }
        $event = app(AuditLogger::class)->record('original');
        DB::unprepared('DROP TRIGGER audit_events_block_update');
        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered']);
        $this->assertFalse(app(AuditIntegrity::class)->verify()['valid']);
        $this->artisan('shopbonus:audit-verify')->assertFailed();
        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'original']);
        $this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
        AuditEvent::create(['occurred_at' => now(), 'category' => 'security', 'action' => 'unsealed', 'outcome' => 'success']);
        $this->assertFalse(app(AuditIntegrity::class)->verify()['valid']);
    }

    public function test_tail_removal_is_detected_even_after_chain_row_deletion(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Destructive trigger-bypass simulation uses transactional SQLite DDL.');
        }
        app(AuditLogger::class)->record('original');
        DB::unprepared('DROP TRIGGER audit_event_seals_block_delete');
        DB::table('audit_event_seals')->delete();
        $this->assertFalse(app(AuditIntegrity::class)->verify()['valid']);
    }

    public function test_verification_detects_unsealed_rows_and_head_corruption(): void
    {
        app(AuditLogger::class)->record('sealed');
        $head = DB::table('audit_chain_heads')->where('id', 1)->value('last_hash');
        DB::table('audit_chain_heads')->where('id', 1)->update(['last_hash' => str_repeat('f', 64)]);
        $this->assertFalse(app(AuditIntegrity::class)->verify()['valid']);
        DB::table('audit_chain_heads')->where('id', 1)->update(['last_hash' => $head]);
        AuditEvent::create(['occurred_at' => now(), 'category' => 'security', 'action' => 'unsealed', 'outcome' => 'success']);
        $this->assertFalse(app(AuditIntegrity::class)->verify()['valid']);
    }

    public function test_database_refuses_seal_modification(): void
    {
        app(AuditLogger::class)->record('original');
        $this->expectException(QueryException::class);
        DB::table('audit_event_seals')->update(['chain_hash' => str_repeat('f', 64)]);
    }
}
