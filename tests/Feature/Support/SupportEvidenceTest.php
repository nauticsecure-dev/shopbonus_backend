<?php

namespace Tests\Feature\Support;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Enums\VoucherOrigin;
use App\Enums\WalletUnit;
use App\Enums\WebshopStatus;
use App\Exceptions\ApiException;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\User;
use App\Models\VoucherCode;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Offers\OfferService;
use App\Services\Support\SupportTools;
use App\Services\Support\ToolEvidence;
use App\Services\Support\VerifiedSupportAnswer;
use App\Services\Vouchers\VoucherInventory;
use App\Services\Wallet\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesActiveWebshops;
use Tests\TestCase;

class SupportEvidenceTest extends TestCase
{
    use CreatesActiveWebshops, RefreshDatabase;

    private function tools(): SupportTools
    {
        return app(SupportTools::class);
    }

    private function answer(User $user, array $evidence, ?array $selection = null, ?array $required = null): array
    {
        return app(VerifiedSupportAnswer::class)->verify($user,
            $selection ?? ['evidence_ids' => array_map(fn ($e) => $e->id, $evidence)], $evidence,
            $required ?? array_map(fn ($e) => $e->tool, $evidence));
    }

    private function fails(string $code, callable $operation, ?int $status = null): void
    {
        try {
            $operation();
            $this->fail('Expected the support security/verification boundary to reject the operation.');
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
            if ($status !== null) {
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function test_exact_wallet_units_reserves_pending_and_spend_are_rendered_without_conversion_or_writes(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $service = app(WalletService::class);
        $wallet = $service->consumerWallet($user);
        $service->credit($wallet, 4000, 'credit');
        $service->addPending($wallet, 700, 'pending');
        $service->reserve($wallet, 500, 'reserve', 60);
        $service->debit($wallet, 2500, 'spend');
        $service->credit($service->consumerWallet($user, WalletUnit::Points), 123, 'points');
        $service->credit($service->consumerWallet($other), 99999, 'other');
        $ledger = WalletLedgerEntry::count();
        $walletCount = Wallet::count();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        $result = $this->answer($user, [$e]);
        $this->assertSame('VERIFIED', $result['status']);
        $available = number_format($e->facts['available_minor'] / 100, 2, '.', '');
        $reserved = number_format($e->facts['reserved_minor'] / 100, 2, '.', '');
        $pending = number_format($e->facts['pending_minor'] / 100, 2, '.', '');
        $spent = number_format($e->facts['spent_minor'] / 100, 2, '.', '');
        $this->assertStringContainsString("EUR {$available}; reserved: EUR {$reserved}; pending: EUR {$pending}; spent: EUR {$spent}", $result['answer']);
        $this->assertSame(1000, $e->facts['available_minor']);
        $this->assertSame(500, $e->facts['reserved_minor']);
        // Pending (awaiting approval) and reserved (purchase in progress) are separate statements.
        $this->assertSame(700, $e->facts['pending_minor']);
        $this->assertStringContainsString('A reward of EUR 7.00 is pending and cannot be spent yet.', $result['answer']);
        $this->assertStringContainsString('EUR 5.00 of your credit is reserved for a purchase in progress.', $result['answer']);
        $this->assertSame(2500, $e->facts['spent_minor']);
        $this->assertSame('EUR_REDEMPTION_CREDIT', $e->facts['unit']);
        $this->assertStringNotContainsString('999', $result['answer']);
        $this->assertNull($result['action']);
        $this->assertSame([$e->id], $result['evidence_ids']);
        $points = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'points']);
        $this->assertNull($points->facts['currency']);
        $this->assertStringContainsString('123 points', $this->answer($user, [$points])['answer']);
        $this->assertSame($ledger, WalletLedgerEntry::count());
        $this->assertSame($walletCount, Wallet::count());
        Http::assertNothingSent();
    }

    public function test_reading_an_absent_wallet_does_not_create_rows_and_remains_scoped(): void
    {
        $user = User::factory()->create();
        $walletCount = Wallet::count();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        $this->assertSame(0, $e->facts['available_minor']);
        $this->answer($user, [$e]);
        $this->assertSame($walletCount, Wallet::count());
        $this->fails('support_answer_invalid', fn () => $this->answer(User::factory()->create(), [$e]));
    }

    #[DataProvider('badTools')]
    public function test_unknown_tools_and_injected_scope_arguments_are_rejected(string $tool, array $arguments): void
    {
        $user = User::factory()->create();
        $this->fails('support_tool_invalid', fn () => $this->tools()->retrieve($user, $tool, $arguments));
    }

    public static function badTools(): array
    {
        return [
            ['refund', []], ['wallet.credit', ['amount' => '999']], ['get_unknown_reward', ['order_reference' => 'guess']],
            ['get_my_wallet', []], ['get_my_wallet', ['unit' => 'EUR']], ['get_my_wallet', ['unit' => 'eur_credit', 'user_id' => 'other']],
            ['get_my_wallet', ['unit' => 'eur_credit', 'workspace_id' => 'other']], ['get_my_wallet', ['unit' => 'eur_credit', 'role' => 'super_admin']],
            ['get_my_wallet', ['unit' => 'eur_credit', 'url' => 'https://example.com']], ['get_my_wallet', ['unit' => ['points']]],
        ];
    }

    public function test_identity_and_support_session_are_checked_again_before_display(): void
    {
        $user = User::factory()->create();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        $this->fails('support_access_denied', fn () => $this->answer($user, [$e]), 403);
        $user->forceFill(['status' => UserStatus::Active, 'email_verified_at' => null])->save();
        $this->fails('support_access_denied', fn () => $this->answer($user, [$e]), 403);
        $user->forceFill(['email_verified_at' => now()])->save();
        Context::add(AuditLogger::CONTEXT_REAL_ACTOR_ID, 123);
        try {
            $this->fails('support_access_denied', fn () => $this->answer($user, [$e]), 403);
        } finally {
            Context::forget(AuditLogger::CONTEXT_REAL_ACTOR_ID);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'support.tool_denied', 'http_status' => 403]);
    }

    public function test_workspace_permissions_membership_and_suspension_are_rechecked(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $employee = User::factory()->create();
        $member = $shop->memberships()->create(['user_id' => $employee->id, 'role' => MembershipRole::Employee,
            'status' => MembershipStatus::Active, 'permissions' => ['finance.view']]);
        $e = $this->tools()->retrieve($employee, 'get_workspace_wallet', [], $shop->public_id);
        $this->assertSame('VERIFIED', $this->answer($employee, [$e])['status']);
        $member->update(['permissions' => []]);
        $this->fails('support_access_denied', fn () => $this->answer($employee, [$e]), 403);
        $member->update(['status' => MembershipStatus::Removed]);
        $this->fails('not_found', fn () => $this->answer($employee, [$e]), 404);
        $ownerEvidence = $this->tools()->retrieve($owner, 'get_workspace_wallet', [], $shop->public_id);
        $shop->forceFill(['status' => WebshopStatus::Suspended])->save();
        $this->fails('support_access_denied', fn () => $this->answer($owner, [$ownerEvidence]), 403);
        $this->assertDatabaseHas('audit_events', ['action' => 'support.tool_denied', 'http_status' => 404]);
    }

    public function test_cross_workspace_and_cross_scope_reads_have_no_privileged_admin_bypass(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $other = User::factory()->create();
        $other->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
        $this->assertTrue($other->isSuperAdmin());
        $this->fails('not_found', fn () => $this->tools()->retrieve($other, 'get_workspace_wallet', [], $shop->public_id), 404);
        $this->fails('not_found', fn () => $this->tools()->retrieve($other, 'get_workspace_wallet', [], 'does-not-exist'), 404);
        $this->fails('support_access_denied', fn () => $this->tools()->retrieve($owner, 'get_workspace_wallet'), 403);
        $this->fails('support_tool_invalid', fn () => $this->tools()->retrieve($owner, 'get_my_wallet', ['unit' => 'eur_credit'], $shop->public_id));
    }

    public function test_fee_preview_reuses_existing_calculator_and_never_invents_unknown_cost(): void
    {
        $owner = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $membership = $shop->memberships()->sole();
        $service = app(OfferService::class);
        $offer = $service->create($membership, OfferAcquisition::MerchantReward, ['name' => 'Test', 'discount_type' => 'fixed', 'discount_value' => 1000]);
        $e = $this->tools()->retrieve($owner, 'get_offer_cost', ['offer_id' => $offer->public_id], $shop->public_id);
        $this->assertSame([1000, 300, 1300], [$e->facts['consumer_reward_minor'], $e->facts['shopbonus_fee_minor'], $e->facts['merchant_total_economic_cost_minor']]);
        $this->assertSame(0.3, $e->facts['shopbonus_fee_rate']);
        $this->assertTrue($e->facts['cost_available']);
        $this->assertStringContainsString('total 1300 minor units', $this->answer($owner, [$e])['answer']);
        $other = Webshop::factory()->withMember($owner)->create();
        $this->fails('not_found', fn () => $this->tools()->retrieve($owner, 'get_offer_cost', ['offer_id' => $offer->public_id], $other->public_id), 404);
        $percentage = $service->create($membership, OfferAcquisition::MerchantReward, ['name' => 'Uncapped', 'discount_type' => 'percentage', 'discount_value' => 1000]);
        $unknown = $this->tools()->retrieve($owner, 'get_offer_cost', ['offer_id' => $percentage->public_id], $shop->public_id);
        $this->assertNull($unknown->facts['merchant_total_economic_cost_minor']);
        $this->assertFalse($unknown->facts['cost_available']);
        $this->assertSame('PARTIALLY_VERIFIED', $this->answer($owner, [$unknown])['status']);
    }

    public function test_voucher_evidence_has_no_secret_and_issuance_is_not_redemption(): void
    {
        $owner = User::factory()->create();
        $consumer = User::factory()->create();
        $shop = $this->activeWebshop($owner);
        $offers = app(OfferService::class);
        $offer = $offers->create($shop->memberships()->sole(), OfferAcquisition::MerchantReward,
            ['name' => 'Test', 'discount_type' => 'fixed', 'discount_value' => 1000, 'redemption_validity_days' => 90]);
        $offer = $offers->transition($offer, OfferStatus::Active);
        VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => 'SECRET-1234', 'code_hash' => VoucherCode::hashFor($shop->id, 'SECRET-1234'), 'code_last4' => '1234']);
        $inventory = app(VoucherInventory::class);
        $voucher = $inventory->reserve($offer, VoucherOrigin::MerchantReward, 'support-test');
        $voucher = $inventory->issue($voucher, $consumer);
        $e = $this->tools()->retrieve($consumer, 'get_my_voucher', ['voucher_id' => $voucher->public_id]);
        $context = json_encode($e->context());
        $this->assertStringNotContainsString('SECRET', $context);
        $this->assertStringNotContainsString('1234', $context);
        $this->assertStringNotContainsString('consumer_user_id', $context);
        $this->assertStringContainsString('No confirmed redemption', $this->answer($consumer, [$e])['answer']);
        $this->fails('not_found', fn () => $this->tools()->retrieve($owner, 'get_my_voucher', ['voucher_id' => $voucher->public_id]), 404);
    }

    private function faq(string $audience = 'consumers', bool $published = true): FaqItem
    {
        $category = FaqCategory::create(['key' => 'support-'.strtolower((string) Str::ulid()), 'names' => ['en' => 'Support', 'nl' => 'Hulp']]);
        $doc = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Gepubliceerde uitleg.']]],
        ]];

        return FaqItem::create(['category_id' => $category->id, 'audience' => $audience, 'published' => $published,
            'translations' => ['nl' => ['question' => 'Hoe?', 'answer' => $doc]]]);
    }

    public function test_free_text_questions_find_the_matching_published_faq(): void
    {
        $category = FaqCategory::create(['key' => 'match-'.strtolower((string) Str::ulid()), 'names' => ['en' => 'Help', 'nl' => 'Hulp']]);
        $make = fn (string $audience, string $question, string $answer, bool $published = true) => FaqItem::create(['category_id' => $category->id, 'audience' => $audience, 'published' => $published,
            'translations' => ['nl' => ['question' => $question, 'answer' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $answer]]]]]]]]);
        $password = $make('public', 'Hoe wijzig ik mijn wachtwoord?', 'Ga naar instellingen en kies wachtwoord wijzigen.');
        $make('consumers', 'Hoe lang is een voucher geldig?', 'Elke voucher toont zijn vervaldatum.');
        $merchantOnly = $make('merchants', 'Hoe koppel ik WooCommerce?', 'Maak een koppeling met je winkel.');
        $make('public', 'Wachtwoord vergeten concept', 'Niet gepubliceerd.', false);

        $this->assertSame($password->public_id, $this->tools()->matchKnowledge(false, 'Ik wil mijn wachtwoord wijzigen', 'nl'));
        $this->assertNull($this->tools()->matchKnowledge(false, 'Hoe koppel ik WooCommerce?', 'nl')); // merchant article, consumer chat
        $this->assertSame($merchantOnly->public_id, $this->tools()->matchKnowledge(true, 'Hoe koppel ik WooCommerce?', 'nl'));
        $this->assertNull($this->tools()->matchKnowledge(false, 'Hoe gaat het?', 'nl'));
    }

    public function test_faq_is_published_audience_scoped_versioned_and_revalidated_with_locale_fallback(): void
    {
        $user = User::factory()->create();
        $item = $this->faq();
        $e = $this->tools()->retrieve($user, 'get_approved_knowledge', ['document_id' => $item->public_id]);
        $this->assertSame('nl', $e->facts['locale']);
        $this->assertNull($e->facts['approved_at']);
        $this->assertStringContainsString('Published FAQ (nl): Gepubliceerde uitleg.', $this->answer($user, [$e])['answer']);
        $item->update(['published' => false]);
        $this->fails('not_found', fn () => $this->answer($user, [$e]), 404);
        $item->update(['published' => true, 'revision' => 2]);
        $this->fails('support_evidence_changed', fn () => $this->answer($user, [$e]), 409);
        $merchant = $this->faq('merchants');
        $this->fails('not_found', fn () => $this->tools()->retrieve($user, 'get_approved_knowledge', ['document_id' => $merchant->public_id]), 404);
        $shop = Webshop::factory()->withMember($user)->create();
        $this->assertSame('merchants', $this->tools()->retrieve($user, 'get_approved_knowledge', ['document_id' => $merchant->public_id], $shop->public_id)->facts['scope']);
        $draft = $this->faq('public', false);
        $this->fails('not_found', fn () => $this->tools()->retrieve($user, 'get_approved_knowledge', ['document_id' => $draft->public_id]), 404);
    }

    public function test_minimal_context_excludes_scope_identifiers_and_locale_and_packet_limits_are_enforced(): void
    {
        $user = User::factory()->create();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        $context = $e->context();
        $this->assertSame(['evidence_id', 'tool', 'checked_at', 'facts'], array_keys($context));
        $this->assertStringNotContainsString($user->email, json_encode($context));
        $this->assertStringNotContainsString($user->public_id, json_encode($context));
        $nl = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit'], locale: 'nl');
        $this->assertStringContainsString('Beschikbaar ShopBonus-tegoed', $this->answer($user, [$nl])['answer']);
        $this->fails('support_answer_invalid', fn () => $this->answer($user, [$e, $nl]));
        $this->fails('support_answer_invalid', fn () => $this->answer($user, array_fill(0, 6, $e)));
        $this->fails('support_tool_invalid', fn () => $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => str_repeat('x', 65)]));
        $this->fails('support_tool_invalid', fn () => $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit'], locale: 'fr'));
    }

    public function test_empty_or_oversized_published_knowledge_is_not_emitted_as_verified_content(): void
    {
        $user = User::factory()->create();
        $item = $this->faq();
        foreach (['', str_repeat('x', 4001)] as $text) {
            $item->update(['translations' => ['nl' => ['question' => 'Hoe?', 'answer' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            ]]]]]);
            $this->fails('support_evidence_unavailable', fn () => $this->tools()->retrieve($user, 'get_approved_knowledge', ['document_id' => $item->public_id]), 409);
        }
    }

    public function test_changed_and_expired_evidence_future_snapshots_and_conflicts_cannot_be_hidden(): void
    {
        $user = User::factory()->create();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        app(WalletService::class)->credit(app(WalletService::class)->consumerWallet($user), 1000, 'new');
        $this->fails('support_evidence_changed', fn () => $this->answer($user, [$e]), 409);
        $fresh = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        $this->fails('support_evidence_conflict', fn () => $this->answer($user, [$e, $fresh], ['evidence_ids' => [$fresh->id]]), 409);
        $this->travel(31)->seconds();
        $this->fails('support_evidence_expired', fn () => $this->answer($user, [$fresh]), 409);
        $future = new ToolEvidence($user->id, $fresh->tool, $fresh->arguments, null, 'en', $fresh->sourceId, $fresh->facts, CarbonImmutable::now()->addSecond());
        $this->fails('support_evidence_expired', fn () => $this->answer($user, [$future]), 409);
    }

    public function test_invented_model_prose_values_dates_actions_and_evidence_ids_are_rejected(): void
    {
        $user = User::factory()->create();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        foreach ([['answer' => 'You will receive EUR 100 tomorrow.'], ['value' => 10000], ['release_date' => '2026-10-27'],
            ['action' => ['route' => 'https://attacker.example']], ['role' => 'super_admin']] as $extra) {
            $this->fails('support_answer_invalid', fn () => $this->answer($user, [$e], ['evidence_ids' => [$e->id]] + $extra));
        }
        foreach ([[], ['unknown'], [$e->id, $e->id], [123], ['foo' => $e->id]] as $ids) {
            $this->fails('support_answer_invalid', fn () => $this->answer($user, [$e], ['evidence_ids' => $ids]));
        }
        $forged = new ToolEvidence($user->id, $e->tool, $e->arguments, null, 'en', $e->sourceId, ['available_minor' => 99999] + $e->facts, CarbonImmutable::now());
        $this->fails('support_evidence_changed', fn () => $this->answer($user, [$forged]), 409);
    }

    public function test_missing_required_tool_never_returns_a_verified_answer_and_scopes_cannot_be_combined(): void
    {
        $user = User::factory()->create();
        $e = $this->tools()->retrieve($user, 'get_my_wallet', ['unit' => 'eur_credit']);
        $answer = $this->answer($user, [$e], required: ['get_my_wallet', 'get_my_voucher']);
        $this->assertSame('NEEDS_CLARIFICATION', $answer['status']);
        $this->assertNull($answer['answer']);
        $this->assertSame([], $answer['evidence_ids']);
        $shop = $this->activeWebshop($user);
        $funding = $this->tools()->retrieve($user, 'get_workspace_wallet', [], $shop->public_id);
        $this->fails('support_answer_invalid', fn () => $this->answer($user, [$e, $funding]));
        $this->fails('support_answer_invalid', fn () => $this->answer($user, [$e], required: ['invent_release_date']));
    }
}
