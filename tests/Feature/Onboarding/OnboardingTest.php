<?php

namespace Tests\Feature\Onboarding;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\VerificationStatus;
use App\Enums\WebshopStatus;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopVerification;
use App\Notifications\OwnershipVerificationCode;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\Network\SafeWebsiteFileFetcher;
use App\Services\Onboarding\Network\WebsiteFileFetcher;
use App\Services\Onboarding\OwnershipVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Fakes\FakeHostResolver;
use Tests\Fakes\FakeWebsiteFileFetcher;
use Tests\TestCase;

/**
 * B03c (Trello #16 E2E 1, 2, 5 and the "Business search & details",
 * "Ownership verification" sections).
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webshop $shop;

    private FakeHostResolver $dns;

    private FakeWebsiteFileFetcher $files;

    private const PROFILE = [
        'legal_name' => 'Fietsen Utrecht B.V.',
        'trading_name' => 'Fietsenwinkel Utrecht',
        'website_url' => 'https://www.Fietsen-Utrecht.nl/winkel',
        'kvk_number' => '1234 5678',
        'vat_number' => 'NL123456789B01',
        'contact_email' => 'info@fietsen-utrecht.nl',
        'contact_phone' => '+31 30 123 4567',
        'main_contact_name' => 'Jan de Vries',
        'address_line' => 'Oudegracht 1',
        'postal_code' => '3511 AA',
        'city' => 'Utrecht',
        'country_code' => 'NL',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');

        $this->dns = new FakeHostResolver;
        $this->files = new FakeWebsiteFileFetcher;
        $this->app->instance(HostResolver::class, $this->dns);
        $this->app->instance(WebsiteFileFetcher::class, $this->files);

        $this->owner = User::factory()->create();
        $this->shop = Webshop::factory()->withMember($this->owner)->create(['created_by' => $this->owner->id]);
        Sanctum::actingAs($this->owner);
    }

    private function url(string $suffix, ?Webshop $shop = null): string
    {
        return '/api/v1/workspaces/'.($shop ?? $this->shop)->public_id.$suffix;
    }

    private function confirmProfile(?Webshop $shop = null, array $overrides = []): void
    {
        $this->putJson($this->url('/business-profile', $shop), [...self::PROFILE, ...$overrides, 'confirm' => true])->assertOk();
    }

    private function start(string $method, array $extra = [], ?Webshop $shop = null): TestResponse
    {
        return $this->postJson($this->url('/ownership/verifications', $shop), ['method' => $method, ...$extra]);
    }

    // ---------------------------------------------------------------- business details (E2E 1)

    public function test_e2e1_manual_entry_draft_then_confirm(): void
    {
        $this->putJson($this->url('/business-profile'), ['legal_name' => 'Fietsen Utrecht B.V.'])
            ->assertOk()
            ->assertJsonPath('data.confirmed', false)
            ->assertJsonPath('data.missing_fields', ['website_url', 'contact_email', 'address_line', 'postal_code', 'city', 'country_code']);

        $this->putJson($this->url('/business-profile'), ['country_code' => 'NL', 'confirm' => true])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'business_details_incomplete')
            ->assertJsonPath('error.details.missing_fields', ['website_url', 'contact_email', 'address_line', 'postal_code', 'city', 'kvk_number']);

        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.confirmed', true)
            ->assertJsonPath('data.website_domain', 'fietsen-utrecht.nl')
            ->assertJsonPath('data.kvk_number', '12345678')
            ->assertJsonPath('data.missing_fields', []);

        // Contact details are encrypted at rest.
        $raw = \DB::table('webshop_business_profiles')->first();
        $this->assertStringNotContainsString('info@fietsen-utrecht.nl', (string) $raw->contact_email);
        $this->assertStringNotContainsString('Jan de Vries', (string) $raw->main_contact_name);
    }

    public function test_e2e1_google_search_and_details_prefill(): void
    {
        config(['shopbonus.onboarding.google_places_api_key' => 'test-key']);
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [
                ['placePrediction' => ['placeId' => 'ChIJ_fietsen_utrecht_01', 'structuredFormat' => [
                    'mainText' => ['text' => 'Fietsenwinkel Utrecht'], 'secondaryText' => ['text' => 'Oudegracht 1, Utrecht'],
                ]]],
            ]]),
            'places.googleapis.com/v1/places/ChIJ_fietsen_utrecht_01*' => Http::response([
                'id' => 'ChIJ_fietsen_utrecht_01',
                'displayName' => ['text' => 'Fietsenwinkel Utrecht', 'languageCode' => 'nl'],
                'formattedAddress' => 'Oudegracht 1, 3511 AA Utrecht, Nederland',
                'addressComponents' => [
                    ['longText' => '1', 'shortText' => '1', 'types' => ['street_number']],
                    ['longText' => 'Oudegracht', 'shortText' => 'Oudegracht', 'types' => ['route']],
                    ['longText' => 'Utrecht', 'shortText' => 'Utrecht', 'types' => ['locality', 'political']],
                    ['longText' => '3511 AA', 'shortText' => '3511 AA', 'types' => ['postal_code']],
                    ['longText' => 'Nederland', 'shortText' => 'NL', 'types' => ['country', 'political']],
                ],
                'websiteUri' => 'https://fietsen-utrecht.nl/',
                'internationalPhoneNumber' => '+31 30 123 4567',
                'primaryType' => 'bicycle_store',
            ]),
        ]);

        $this->getJson('/api/v1/business-directory/search?query=fietsen+utrecht')
            ->assertOk()
            ->assertJsonPath('data.0.place_id', 'ChIJ_fietsen_utrecht_01')
            ->assertJsonPath('data.0.name', 'Fietsenwinkel Utrecht');

        $this->getJson('/api/v1/business-directory/places/ChIJ_fietsen_utrecht_01')
            ->assertOk()
            ->assertJsonPath('data.address_line', 'Oudegracht 1')
            ->assertJsonPath('data.postal_code', '3511 AA')
            ->assertJsonPath('data.city', 'Utrecht')
            ->assertJsonPath('data.country_code', 'NL')
            ->assertJsonPath('data.category', 'bicycle_store');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'test-key')
            && $request->hasHeader('X-Goog-FieldMask'));

        // Selecting a Google result grants nothing: ownership is still unverified.
        $this->assertNull($this->shop->fresh()->ownership_verified_at);
    }

    public function test_business_search_without_key_is_503_and_provider_errors_are_502(): void
    {
        config(['shopbonus.onboarding.google_places_api_key' => null]);
        $this->getJson('/api/v1/business-directory/search?query=fietsen')
            ->assertStatus(503)->assertJsonPath('error.code', 'provider_not_configured');

        config(['shopbonus.onboarding.google_places_api_key' => 'test-key']);
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);
        $this->getJson('/api/v1/business-directory/search?query=boeken')
            ->assertStatus(502)->assertJsonPath('error.code', 'provider_error');
    }

    public function test_profile_validation(): void
    {
        $this->putJson($this->url('/business-profile'), ['kvk_number' => '123', 'vat_number' => 'NL12', 'country_code' => 'nl', 'contact_phone' => 'call me'])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['fields' => ['kvk_number', 'vat_number', 'country_code', 'contact_phone']]]);

        foreach (['http://127.0.0.1/', 'localhost', 'intranet.local'] as $bad) {
            $this->putJson($this->url('/business-profile'), ['website_url' => $bad])
                ->assertStatus(422)->assertJsonPath('error.code', 'invalid_domain');
        }
    }

    public function test_changing_a_confirmed_profile_requires_confirming_again(): void
    {
        $this->confirmProfile();

        $this->putJson($this->url('/business-profile'), ['city' => 'Amersfoort'])
            ->assertOk()->assertJsonPath('data.confirmed', false);
    }

    public function test_profile_permissions(): void
    {
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        foreach ([[$employee, MembershipRole::Employee], [$manager, MembershipRole::Manager]] as [$u, $role]) {
            $this->shop->memberships()->create(['user_id' => $u->id, 'role' => $role, 'status' => MembershipStatus::Active, 'joined_at' => now()]);
        }

        Sanctum::actingAs($employee);
        $this->getJson($this->url('/business-profile'))->assertForbidden();

        Sanctum::actingAs($manager);
        $this->putJson($this->url('/business-profile'), [...self::PROFILE, 'confirm' => true])->assertOk();
        // Ownership actions are owner-only.
        $this->start('dns_txt')->assertForbidden()->assertJsonPath('error.details.required_permission', 'ownership.manage');
    }

    // ---------------------------------------------------------------- ownership (E2E 2)

    public function test_e2e2_dns_challenge_succeeds_and_locks_the_domain(): void
    {
        $this->confirmProfile();

        $response = $this->start('dns_txt')
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.instructions.record_type', 'TXT')
            ->assertJsonPath('data.instructions.record_name', '_shopbonus-verification.fietsen-utrecht.nl');
        $id = $response->json('data.id');
        $value = $response->json('data.instructions.record_value');
        $this->assertSame(WebshopStatus::AwaitingVerification, $this->shop->fresh()->status);

        $this->postJson($this->url("/ownership/verifications/{$id}/check"))
            ->assertStatus(422)->assertJsonPath('error.code', 'challenge_not_found');

        $this->dns->txt['_shopbonus-verification.fietsen-utrecht.nl'] = ['some-other-record', " {$value} "];

        $this->postJson($this->url("/ownership/verifications/{$id}/check"))
            ->assertOk()->assertJsonPath('data.status', 'verified');

        $shop = $this->shop->fresh();
        $this->assertNotNull($shop->ownership_verified_at);
        $this->assertSame('dns_txt', $shop->ownership_method);
        $this->assertSame('fietsen-utrecht.nl', $shop->verified_domain);
        $this->assertSame(WebshopStatus::Draft, $shop->status);
        $this->assertSame(1, AuditEvent::where('action', 'ownership.verified')->count());

        // Replay: the same verification cannot be completed again.
        $this->postJson($this->url("/ownership/verifications/{$id}/check"))
            ->assertStatus(409)->assertJsonPath('error.code', 'verification_not_pending');
        $this->start('dns_txt')->assertStatus(409)->assertJsonPath('error.code', 'ownership_already_verified');

        $this->putJson($this->url('/business-profile'), ['website_url' => 'https://ander-domein.nl'])
            ->assertStatus(409)->assertJsonPath('error.code', 'domain_locked');
        $this->putJson($this->url('/business-profile'), ['website_url' => 'https://fietsen-utrecht.nl/nieuw'])->assertOk();
    }

    public function test_e2e2_website_file_challenge(): void
    {
        $this->confirmProfile();
        $response = $this->start('website_file')->assertCreated()
            ->assertJsonPath('data.instructions.url', 'https://fietsen-utrecht.nl/.well-known/shopbonus-verification.txt');
        $id = $response->json('data.id');

        $this->files->files['fietsen-utrecht.nl'.OwnershipVerification::FILE_PATH] = 'wrong-value';
        $this->postJson($this->url("/ownership/verifications/{$id}/check"))->assertStatus(422);

        $this->files->files['fietsen-utrecht.nl'.OwnershipVerification::FILE_PATH] = $response->json('data.instructions.file_contents')."\n";
        $this->postJson($this->url("/ownership/verifications/{$id}/check"))->assertOk()->assertJsonPath('data.status', 'verified');
    }

    public function test_e2e2_expired_challenge_fails_and_can_be_restarted(): void
    {
        $this->confirmProfile();
        $id = $this->start('dns_txt')->json('data.id');
        $this->travel(8)->days();

        $this->postJson($this->url("/ownership/verifications/{$id}/check"))
            ->assertStatus(410)->assertJsonPath('error.code', 'verification_expired');
        $this->assertSame(WebshopStatus::VerificationFailed, $this->shop->fresh()->status);
        $this->assertSame(VerificationStatus::Failed, WebshopVerification::sole()->status);

        $this->start('dns_txt')->assertCreated();
        $this->assertSame(WebshopStatus::AwaitingVerification, $this->shop->fresh()->status);
    }

    public function test_e2e2_superseded_challenge_cannot_be_used(): void
    {
        $this->confirmProfile();
        $first = $this->start('dns_txt');
        $this->start('website_file')->assertCreated();

        $this->dns->txt['_shopbonus-verification.fietsen-utrecht.nl'] = [$first->json('data.instructions.record_value')];
        $this->postJson($this->url('/ownership/verifications/'.$first->json('data.id').'/check'))
            ->assertStatus(409)->assertJsonPath('error.code', 'verification_not_pending');
    }

    public function test_e2e2_domain_email_code(): void
    {
        $this->confirmProfile();

        $this->start('domain_email', ['mailbox' => 'info'])
            ->assertStatus(422)->assertJsonPath('error.code', 'mailbox_not_allowed');

        $response = $this->start('domain_email', ['mailbox' => 'Admin'])
            ->assertCreated()->assertJsonPath('data.instructions.sent_to', 'a****@fietsen-utrecht.nl');
        $id = $response->json('data.id');

        $code = null;
        Notification::assertSentTo(new AnonymousNotifiable, OwnershipVerificationCode::class, function ($mail, $channels, $notifiable) use (&$code) {
            $this->assertSame('admin@fietsen-utrecht.nl', $notifiable->routes['mail']);
            $code = $mail->code;

            return true;
        });
        $this->assertStringNotContainsString((string) $code, json_encode(WebshopVerification::sole()->getAttributes()));

        $wrong = str_pad((string) (((int) $code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        $this->postJson($this->url("/ownership/verifications/{$id}/confirm-code"), ['code' => $wrong])
            ->assertStatus(422)->assertJsonPath('error.code', 'verification_code_invalid');

        $this->postJson($this->url("/ownership/verifications/{$id}/confirm-code"), ['code' => $code])
            ->assertOk()->assertJsonPath('data.status', 'verified');
        $this->assertSame('domain_email', $this->shop->fresh()->ownership_method);
    }

    public function test_e2e2_domain_email_attempt_limit_fails_the_challenge(): void
    {
        $this->confirmProfile();
        $id = $this->start('domain_email', ['mailbox' => 'webmaster'])->json('data.id');

        foreach (range(1, 4) as $i) {
            $this->postJson($this->url("/ownership/verifications/{$id}/confirm-code"), ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson($this->url("/ownership/verifications/{$id}/confirm-code"), ['code' => '000000'])
            ->assertJsonPath('error.code', 'verification_attempts_exceeded');

        $this->assertSame(VerificationStatus::Failed, WebshopVerification::sole()->status);
        $this->assertSame(WebshopStatus::VerificationFailed, $this->shop->fresh()->status);
    }

    public function test_free_email_domains_cannot_use_email_verification(): void
    {
        $this->confirmProfile(null, ['website_url' => 'https://gmail.com']);

        $this->start('domain_email', ['mailbox' => 'admin'])
            ->assertStatus(422)->assertJsonPath('error.code', 'free_email_domain');
    }

    public function test_duplicate_domain_claim_must_go_through_flagged_review(): void
    {
        $this->confirmProfile();
        $value = $this->start('dns_txt')->json('data.instructions.record_value');
        $this->dns->txt['_shopbonus-verification.fietsen-utrecht.nl'] = [$value];
        $this->postJson($this->url('/ownership/verifications/'.WebshopVerification::sole()->public_id.'/check'))->assertOk();

        $rival = User::factory()->create();
        $rivalShop = Webshop::factory()->withMember($rival)->create(['created_by' => $rival->id]);
        Sanctum::actingAs($rival);
        $this->confirmProfile($rivalShop);

        $this->start('dns_txt', [], $rivalShop)->assertStatus(409)->assertJsonPath('error.code', 'domain_already_claimed');

        $this->postJson($this->url('/ownership/review', $rivalShop), ['notes' => 'We bought this shop in 2026; contract attached.'])
            ->assertCreated()->assertJsonPath('data.status', 'submitted');

        $rivalShop->refresh();
        $this->assertSame(WebshopStatus::PendingReview, $rivalShop->status);
        $this->assertSame(['duplicate_domain_claim'], $rivalShop->review_flags);
        $this->assertNull($rivalShop->ownership_verified_at);
    }

    public function test_manual_review_stores_private_encrypted_evidence(): void
    {
        $this->confirmProfile();
        $pdf = UploadedFile::fake()->createWithContent('KvK uittreksel.pdf', '%PDF-1.4 secret registry extract');

        $response = $this->post($this->url('/ownership/review'), [
            'notes' => 'Our domain registrar does not allow TXT records.',
            'evidence' => [$pdf],
        ], ['Accept' => 'application/json'])->assertCreated();

        $response->assertJsonMissingPath('data.evidence');
        $this->assertSame(WebshopStatus::PendingReview, $this->shop->fresh()->status);

        $stored = Storage::disk('local')->allFiles('verification-evidence/'.$this->shop->public_id);
        $this->assertCount(1, $stored);
        $this->assertStringNotContainsString('secret registry extract', Storage::disk('local')->get($stored[0]));

        $evidence = WebshopVerification::sole()->evidence;
        $this->assertSame('KvK_uittreksel.pdf', $evidence['files'][0]['name']);
        $raw = \DB::table('webshop_verifications')->value('evidence');
        $this->assertStringNotContainsString('registrar', (string) $raw);

        $this->post($this->url('/ownership/review'), ['notes' => 'again please review'], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.code', 'review_in_progress');
    }

    public function test_manual_review_rejects_disallowed_files(): void
    {
        $this->confirmProfile();

        $this->post($this->url('/ownership/review'), [
            'notes' => 'Evidence attached as requested.',
            'evidence' => [UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;')],
        ], ['Accept' => 'application/json'])->assertStatus(422);
    }

    // ---------------------------------------------------------------- progress (E2E 5)

    public function test_e2e5_progress_is_derived_and_steps_cannot_be_skipped(): void
    {
        $steps = fn () => collect($this->getJson($this->url('/onboarding'))->assertOk()->json('data.steps'))->pluck('status', 'key')->all();

        $this->assertSame(['business_details' => 'not_started', 'ownership' => 'not_started', 'agreement' => 'not_started', 'activation' => 'not_started'], $steps());
        $this->assertSame('business_details', $this->getJson($this->url('/onboarding'))->json('data.next_step'));

        // Skipping ahead is refused server-side.
        $this->start('dns_txt')->assertStatus(409)->assertJsonPath('error.code', 'business_details_incomplete');
        $this->post($this->url('/ownership/review'), ['notes' => 'please just approve us'], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('error.code', 'business_details_incomplete');
        $this->putJson($this->url('/onboarding'), ['steps' => ['ownership' => 'complete']])->assertStatus(405);

        $this->putJson($this->url('/business-profile'), ['legal_name' => 'Draft B.V.']);
        $this->assertSame('in_progress', $steps()['business_details']);

        // Resume: confirm later and continue where we left off.
        $this->confirmProfile();
        $this->assertSame('complete', $steps()['business_details']);
        $this->assertSame('ownership', $this->getJson($this->url('/onboarding'))->json('data.next_step'));

        $value = $this->start('dns_txt')->json('data.instructions.record_value');
        $this->assertSame('in_progress', $steps()['ownership']);
        $this->dns->txt['_shopbonus-verification.fietsen-utrecht.nl'] = [$value];
        $this->postJson($this->url('/ownership/verifications/'.WebshopVerification::sole()->public_id.'/check'))->assertOk();

        $this->assertSame('complete', $steps()['ownership']);
        $this->assertSame('agreement', $this->getJson($this->url('/onboarding'))->json('data.next_step'));
    }

    public function test_verification_ids_of_other_webshops_are_not_found(): void
    {
        $rival = User::factory()->create();
        $rivalShop = Webshop::factory()->withMember($rival)->create(['created_by' => $rival->id]);
        Sanctum::actingAs($rival);
        $this->confirmProfile($rivalShop, ['website_url' => 'https://rival.nl']);
        $foreignId = $this->start('dns_txt', [], $rivalShop)->json('data.id');

        Sanctum::actingAs($this->owner);
        $this->postJson($this->url("/ownership/verifications/{$foreignId}/check"))->assertNotFound();
    }

    // ---------------------------------------------------------------- SSRF-safe fetcher

    public function test_safe_fetcher_never_connects_to_private_addresses(): void
    {
        Http::fake();
        $this->dns->addresses['evil.example.nl'] = ['169.254.169.254'];
        $this->dns->addresses['mixed.example.nl'] = ['93.184.216.34', '10.0.0.5'];

        $fetcher = new SafeWebsiteFileFetcher($this->dns);

        $this->assertNull($fetcher->fetch('evil.example.nl', OwnershipVerification::FILE_PATH));
        $this->assertNull($fetcher->fetch('mixed.example.nl', OwnershipVerification::FILE_PATH));
        $this->assertNull($fetcher->fetch('unresolvable.example.nl', OwnershipVerification::FILE_PATH));
        Http::assertNothingSent();
    }

    public function test_safe_fetcher_reads_public_https_file_and_ignores_redirects(): void
    {
        $this->dns->addresses['fietsen-utrecht.nl'] = ['93.184.216.34'];
        $this->dns->addresses['redirecting.nl'] = ['93.184.216.34'];
        Http::fake([
            'https://fietsen-utrecht.nl/.well-known/shopbonus-verification.txt' => Http::response(str_repeat('a', 5000)),
            'https://redirecting.nl/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        ]);

        $fetcher = new SafeWebsiteFileFetcher($this->dns);

        $this->assertSame(1024, strlen((string) $fetcher->fetch('fietsen-utrecht.nl', OwnershipVerification::FILE_PATH)));
        $this->assertNull($fetcher->fetch('redirecting.nl', OwnershipVerification::FILE_PATH));
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254'));
    }
}
