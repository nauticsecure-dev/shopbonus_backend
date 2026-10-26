<?php

namespace Tests\Feature\Notifications;

use App\Events\NotificationInboxChanged;
use App\Models\CentralNotification;
use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\SupportReviews;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class NotificationCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Exercise genuine commits without the outer RefreshDatabase transaction.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->beforeApplicationDestroyed(function () {
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_broadcasts_only_after_commit_and_rollback_publishes_nothing(): void
    {
        Event::fake([NotificationInboxChanged::class]);
        $user = User::factory()->create();
        $c = SupportConversation::create(['user_id' => $user->id, 'locale' => 'en', 'expires_at' => now()->addDays(30)]);
        DB::beginTransaction();
        app(SupportReviews::class)->reply($user, $c, 'Private reply');
        Event::assertNotDispatched(NotificationInboxChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(NotificationInboxChanged::class);
        $this->assertSame(0, CentralNotification::count());
        app(SupportReviews::class)->reply($user, $c, 'Committed reply');
        Event::assertDispatchedTimes(NotificationInboxChanged::class, 2);
        Event::assertDispatched(NotificationInboxChanged::class, fn ($e) => $e->broadcastAs() === 'NotificationCreated');
    }

    public function test_queue_outage_cannot_lose_a_committed_reply_or_read_state(): void
    {
        Event::listen(NotificationInboxChanged::class, fn () => throw new RuntimeException('Fixture queue unavailable'));
        $user = User::factory()->create();
        $c = SupportConversation::create(['user_id' => $user->id, 'locale' => 'en', 'expires_at' => now()->addDays(30)]);
        $reply = app(SupportReviews::class)->reply($user, $c, 'Persist despite queue outage');
        $this->assertNotNull($reply->fresh());
        $this->assertSame(1, CentralNotification::count());
        Sanctum::actingAs($user);
        $id = CentralNotification::firstOrFail()->id;
        $this->patchJson('/api/v1/notifications/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread_count', 0)->assertJsonPath('meta.total', 1);
    }
}
