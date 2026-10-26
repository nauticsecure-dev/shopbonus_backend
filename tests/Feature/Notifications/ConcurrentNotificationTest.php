<?php

namespace Tests\Feature\Notifications;

use App\Models\CentralNotification;
use App\Models\NotificationDelivery;
use App\Models\PlatformSettingVersion;
use App\Models\SupportConversation;
use App\Models\SupportReply;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationInbox;
use App\Services\Notifications\NotificationMail;
use App\Services\Notifications\NotificationSources;
use App\Services\Notifications\NotificationTemplateAi;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    public function test_concurrent_template_generation_reserves_one_global_provider_slot(): void
    {
        $this->skipUnlessRealDatabase();
        config(['notifications.ai_enabled' => true, 'notifications.api_key' => 'fixture-key', 'notifications.model' => 'fixture-model', 'notifications.daily_limit' => 1]);
        $users = User::factory()->count(2)->create();
        foreach ($users as $u) {
            $u->platformRoles()->create(['role' => 'super_admin', 'granted_via' => 'console', 'granted_at' => now()]);
        }
        Http::fake(fn () => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => json_encode(array_fill_keys(['nl', 'en'], ['title' => 'Support', 'body' => 'View your account.', 'action_label' => 'View details']))]]]]]));
        $results = $this->race(2, function ($worker) use ($users) {
            app(NotificationTemplateAi::class)->generate(User::findOrFail($users[$worker]->id), 'support_reply', (string) Str::uuid());

            return 'ok';
        });
        sort($results);
        $this->assertSame(['err:notification_ai_daily_limit', 'ok'], $results);
        $this->assertSame(1, (int) DB::table('notification_ai_quotas')->value('requests'));
        $this->assertSame(2, PlatformSettingVersion::count());
    }

    public function test_concurrent_mail_workers_claim_one_attempt(): void
    {
        $this->skipUnlessRealDatabase();
        config(['notifications.email_enabled' => true]);
        $user = User::factory()->create();
        app(NotificationSources::class)->capture('voucher_received', 'mail-race-fixture', [$user->id]);
        app(NotificationDispatcher::class)->drain();
        $d = NotificationDelivery::firstOrFail();
        $results = $this->race(4, function ($worker) use ($d) {
            app(NotificationMail::class)->send($d->id);

            return 'ok';
        });
        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $results);
        $this->assertSame('sent', $d->fresh()->status);
        $this->assertSame(1, $d->fresh()->attempts);
    }
}
