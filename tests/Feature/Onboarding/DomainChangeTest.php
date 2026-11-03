<?php

namespace Tests\Feature\Onboarding;

use App\Enums\EcommercePlatform;
use App\Enums\PlatformRole;
use App\Enums\VerificationMethod;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Integrations\ConnectionManager;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Onboarding\BusinessProfileService;
use App\Services\Onboarding\DomainChange;
use App\Services\Onboarding\Network\HostResolver;
use App\Services\Onboarding\OwnershipVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class DomainChangeTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $shop = $this->activeWebshop($owner, ['verified_domain' => 'old-store.nl']);
        BusinessProfile::forceCreate(['webshop_id' => $shop->id, 'website_url' => 'https://old-store.nl', 'website_domain' => 'old-store.nl', 'confirmed_at' => now(), 'confirmed_by' => $owner->id]);

        return [$owner, $admin, $shop];
    }

    public function test_console_domain_change_resets_proof_connections_and_confirmation_without_erasing_history(): void
    {
        [$owner, $admin, $shop] = $this->fixture();
        [$connection] = app(ConnectionManager::class)->create($shop->memberships()->sole(), EcommercePlatform::WooCommerce, []);
        $generation = $connection->credential_generation;
        $attempt = $shop->verifications()->create(['method' => VerificationMethod::DnsTxt, 'status' => 'pending', 'domain' => 'old-store.nl', 'created_by' => $owner->id]);
        $history = $shop->verifications()->create(['method' => VerificationMethod::DnsTxt, 'status' => 'verified', 'domain' => 'old-store.nl', 'verified_at' => now(), 'created_by' => $owner->id]);
        $args = ['webshop' => $shop->public_id, '--actor' => $admin->public_id, '--expected-domain' => 'old-store.nl', '--website' => 'https://new-store.nl', '--reference' => 'SYNTHETIC-CHANGE-42'];
        $this->artisan('shopbonus:change-verified-domain', $args)->assertFailed();
        $args['--reset-verification'] = true;
        $this->artisan('shopbonus:change-verified-domain', array_replace($args, ['--actor' => $owner->public_id]))->assertFailed();
        $this->artisan('shopbonus:change-verified-domain', array_replace($args, ['--expected-domain' => 'stale-store.nl']))->assertFailed();
        $this->artisan('shopbonus:change-verified-domain', $args)->assertSuccessful();
        $current = $shop->fresh();
        $this->assertFalse($current->isOwnershipVerified());
        $this->assertNull($current->verified_domain);
        $this->assertSame(WebshopStatus::AwaitingVerification, $current->status);
        $this->assertNull($current->approved_at);
        $this->assertNull($current->businessProfile->confirmed_at);
        $this->assertSame('new-store.nl', $current->businessProfile->website_domain);
        $this->assertSame('superseded', $attempt->fresh()->status->value);
        $this->assertSame('verified', $history->fresh()->status->value);
        $this->assertNotNull($history->fresh()->verified_at);
        $this->assertSame('disconnected', $connection->fresh()->status->value);
        $this->assertNull($connection->fresh()->credentials);
        $this->assertSame($generation + 1, $connection->fresh()->credential_generation);
        $this->assertDatabaseHas('audit_events', ['action' => 'onboarding.domain_changed', 'actor_user_id' => $admin->id, 'webshop_id' => $shop->id]);
        $this->artisan('shopbonus:change-verified-domain', $args)->assertFailed();
        $this->assertSame($generation + 1, $connection->fresh()->credential_generation);
        $this->assertDatabaseCount('webshop_agreement_acceptances', 1);
    }

    public function test_domain_change_cannot_bypass_suspension_or_rejection_or_use_revoked_actor(): void
    {
        [$owner, $admin, $shop] = $this->fixture();
        foreach ([WebshopStatus::Suspended, WebshopStatus::Rejected] as $status) {
            $shop->forceFill(['status' => $status])->save();
            try {
                app(DomainChange::class)->change($shop, $admin, 'old-store.nl', 'new-store.nl', 'CASE-42');
                $this->fail('Blocked shop changed domain.');
            } catch (ApiException $e) {
                $this->assertSame('domain_change_status_blocked', $e->errorCode);
            }
        }
        $shop->forceFill(['status' => WebshopStatus::Approved])->save();
        $admin->platformRoles()->update(['revoked_at' => now()]);
        try {
            app(DomainChange::class)->change($shop, $admin, 'old-store.nl', 'new-store.nl', 'CASE-42');
            $this->fail('Revoked actor accepted.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->status);
        }
        $this->assertSame('old-store.nl', $shop->fresh()->verified_domain);
    }

    public function test_profile_updates_recheck_current_ownership_instead_of_trusting_stale_model(): void
    {
        [$owner, $admin, $shop] = $this->fixture();
        $stale = Webshop::findOrFail($shop->id);
        $stale->ownership_verified_at = null;
        try {
            app(BusinessProfileService::class)->update($stale, $owner, ['website_url' => 'https://other-store.nl'], false);
            $this->fail('Stale model bypassed domain lock.');
        } catch (ApiException $e) {
            $this->assertSame('domain_locked', $e->errorCode);
        }
        $this->assertSame('old-store.nl', $shop->fresh()->businessProfile->website_domain);
    }

    public function test_verified_dns_response_cannot_complete_after_the_profile_domain_changes(): void
    {
        [$owner, $admin, $shop] = $this->fixture();
        $shop->forceFill(['status' => WebshopStatus::AwaitingVerification, 'ownership_verified_at' => null, 'verified_domain' => null])->save();
        $verification = $shop->verifications()->create(['method' => VerificationMethod::DnsTxt, 'status' => 'pending', 'domain' => 'old-store.nl', 'challenge_value' => 'synthetic-dns-challenge', 'expires_at' => now()->addHour(), 'created_by' => $owner->id]);
        $this->app->bind(HostResolver::class, fn () => new class($shop) implements HostResolver
        {
            public function __construct(private Webshop $shop) {}

            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }

            public function txtRecords(string $host): array
            {
                $this->shop->businessProfile()->update(['website_domain' => 'new-store.nl']);

                return ['synthetic-dns-challenge'];
            }
        });
        try {
            app(OwnershipVerification::class)->check($shop->memberships()->sole(), $verification);
            $this->fail('Old-domain evidence completed after profile change.');
        } catch (ApiException $e) {
            $this->assertSame('verification_domain_changed', $e->errorCode);
        }
        $this->assertNull($shop->fresh()->ownership_verified_at);
    }
}
