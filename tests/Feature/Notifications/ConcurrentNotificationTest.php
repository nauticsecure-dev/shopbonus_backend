<?php

namespace Tests\Feature\Notifications;

use App\Models\CentralNotification;
use App\Models\SupportConversation;
use App\Models\SupportReply;
use App\Models\User;
use App\Services\Notifications\NotificationInbox;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RacesProcesses;
use Tests\TestCase;

class ConcurrentNotificationTest extends TestCase
{
    use DatabaseTruncation, RacesProcesses {
        DatabaseTruncation::truncateDatabaseTables as truncateCommittedTables;
    }

    protected function truncateDatabaseTables(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->truncateCommittedTables();
        }
    }

    protected function tearDown(): void
    {
        $this->truncateAfterRace();
        parent::tearDown();
    }

    public function test_concurrent_publication_of_one_reply_creates_one_recipient_event(): void
    {
        $this->skipUnlessRealDatabase();
        Queue::fake();
        $user = User::factory()->create();
        $c = SupportConversation::create(['user_id' => $user->id, 'locale' => 'nl', 'expires_at' => now()->addDays(30)]);
        $r = SupportReply::create(['conversation_id' => $c->id, 'created_by' => $user->id, 'body' => 'Fixture reply']);
        $results = $this->race(4, function ($worker) use ($c, $r) {
            DB::transaction(fn () => app(NotificationInbox::class)->supportReply($c, $r));

            return 'ok';
        });
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $results, implode(', ', $results));
        $this->assertSame(1, CentralNotification::count());
    }
}
