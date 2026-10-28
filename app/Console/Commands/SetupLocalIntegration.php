<?php

namespace App\Console\Commands;

use App\Enums\AgreementTemplateStatus;
use App\Enums\AuditCategory;
use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Enums\PlatformRole;
use App\Models\AgreementTemplate;
use App\Models\CmsPage;
use App\Models\FaqCategory;
use App\Models\User;
use App\Models\VoucherCode;
use App\Services\Agreements\AgreementService;
use App\Services\Audit\AuditLogger;
use App\Services\Cms\CmsService;
use App\Services\Discovery\PublicProfileService;
use App\Services\Offers\OfferService;
use App\Services\Onboarding\BusinessProfileService;
use App\Services\Onboarding\OwnershipVerification;
use App\Services\Support\SupportKnowledge;
use App\Services\Workspaces\WebshopLifecycle;
use App\Services\Workspaces\WorkspaceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/** Explicit synthetic local fixtures; never called by DatabaseSeeder or deployments. */
class SetupLocalIntegration extends Command
{
    protected $signature = 'shopbonus:setup-local-integration';

    protected $description = 'Create labelled LOCAL SAMPLE integration data and a private credentials file (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! in_array(config('mail.default'), ['log', 'array'], true)
            || config('shopbonus.payments.mollie.allow_live') || config('shopbonus.answer24.dispatch_enabled')) {
            $this->error('Local/testing environment, log/array mail, live payments disabled and Answer24 dispatch disabled are required.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/local-integration');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            $this->error('Cannot create the private fixture directory.');

            return self::FAILURE;
        }
        $file = $directory.'/credentials.json';
        if (is_link($directory) || is_link($file)) {
            $this->error('Fixture paths must not be symlinks.');

            return self::FAILURE;
        }
        chmod($directory, 0700);
        if (file_exists($file)) {
            try {
                $manifest = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                if (($manifest['fixture'] ?? null) !== 'shopbonus-local-v1' || count($manifest['accounts'] ?? []) !== 4) {
                    throw new \RuntimeException;
                }
                foreach ($manifest['accounts'] as $account) {
                    $user = User::where('public_id', $account['id'])->where('email', $account['email'])->first();
                    if ($user === null || ! Hash::check($account['password'], $user->getAuthPassword())) {
                        throw new \RuntimeException;
                    }
                }
                chmod($file, 0600);
                $this->info('Existing fixtures preserved; no passwords, content or stock were reset. Credentials: '.$file);

                return self::SUCCESS;
            } catch (Throwable) {
                $this->error('The existing fixture file does not match this database. Nothing was changed. Use a separate local database/storage path.');

                return self::FAILURE;
            }
        }
        $previousMask = umask(0077);
        $handle = fopen($file, 'x');
        umask($previousMask);
        if ($handle === false) {
            $this->error('Cannot exclusively create the private credentials file.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($handle) {
                // A lost manifest must never cause account takeover or replacement of real content.
                if (User::whereIn('email', array_map(fn ($role) => 'local-'.$role.'@shopbonus.example.test', ['admin', 'reviewer', 'merchant', 'consumer']))->exists()
                    || CmsPage::where('key', 'like', 'local-integration-%')->exists()
                    || FaqCategory::where('key', 'local-integration')->exists()) {
                    throw new \RuntimeException('Fixture identities/content already exist without their manifest. Nothing was changed.');
                }
                $accounts = [];
                $users = [];
                foreach (['admin', 'reviewer', 'merchant', 'consumer'] as $role) {
                    $password = Str::password(28);
                    $user = User::create(['first_name' => 'LOCAL SAMPLE '.ucfirst($role), 'email' => 'local-'.$role.'@shopbonus.example.test',
                        'password' => $password, 'locale' => 'en', 'onboarding_intent' => $role === 'merchant' ? 'merchant' : 'consumer']);
                    $user->forceFill(['email_verified_at' => now()])->save();
                    if (in_array($role, ['admin', 'reviewer'], true)) {
                        $user->platformRoles()->create(['role' => PlatformRole::SuperAdmin, 'granted_via' => 'console', 'granted_at' => now()]);
                        app(AuditLogger::class)->record('admin.super_admin_granted', category: AuditCategory::Admin, resource: $user,
                            metadata: ['via' => 'local-integration-fixture']);
                    }
                    $users[$role] = $user;
                    $accounts[$role] = ['id' => $user->public_id, 'email' => $user->email, 'password' => $password];
                }
                $admin = $users['admin'];
                $cms = app(CmsService::class);
                foreach (['terms', 'privacy', 'content'] as $type) {
                    // Never supersede any existing legal page, including untranslated or private publications.
                    if ($type !== 'content' && CmsPage::where('type', $type)->exists()) {
                        continue;
                    }
                    $locales = [];
                    foreach (['nl', 'en'] as $locale) {
                        $locales[$locale] = ['slug' => 'local-sample-'.$type, 'content' => ['title' => 'LOCAL SAMPLE '.$type.' — NOT APPROVED',
                            'body' => self::body('LOCAL SAMPLE ONLY. Synthetic integration content. Not approved legal text. Do not use in production.')]];
                    }
                    $page = $cms->create('local-integration-'.$type, $type === 'content' ? 'info' : $type, 'public', $locales, $admin->id);
                    foreach (['nl', 'en'] as $locale) {
                        $cms->publish($page, $locale, $admin->id, $type === 'content' ? [] : ['legal_version' => 'LOCAL-SAMPLE-1', 'effective_date' => now()->toDateString(), 'approve' => true]);
                    }
                }
                $category = FaqCategory::create(['key' => 'local-integration', 'names' => ['nl' => 'LOCAL SAMPLE', 'en' => 'LOCAL SAMPLE'], 'position' => 999]);
                app(SupportKnowledge::class)->create($category, ['audience' => 'public', 'published' => true, 'translations' => [
                    'nl' => ['question' => 'LOCAL SAMPLE: wat is dit?', 'answer' => self::body('Alleen synthetische lokale testgegevens.')],
                    'en' => ['question' => 'LOCAL SAMPLE: what is this?', 'answer' => self::body('Synthetic local integration data only.')],
                ]], $admin);
                $shop = app(WorkspaceService::class)->createDraft($users['merchant'], ['name' => 'LOCAL SAMPLE Shop']);
                app(BusinessProfileService::class)->update($shop, $users['merchant'], ['legal_name' => 'LOCAL SAMPLE Business', 'website_url' => 'https://shopbonus-local-sample.example.com',
                    'contact_email' => $users['merchant']->email, 'address_line' => 'Sample Street 1', 'postal_code' => '0000AA', 'city' => 'Sample', 'country_code' => 'NL', 'kvk_number' => '00000000'], true);
                $shop = $shop->fresh();
                $member = $shop->memberships()->sole()->setRelation('webshop', $shop);
                app(OwnershipVerification::class)->submitManualReview($member, 'LOCAL SAMPLE ONLY — synthetic fixture, no real domain proof.', []);
                if (! AgreementTemplate::where('key', AgreementTemplate::MERCHANT)->where('status', AgreementTemplateStatus::Published)->exists()) {
                    AgreementTemplate::create(['key' => AgreementTemplate::MERCHANT, 'version' => 'LOCAL-SAMPLE-1', 'locale' => 'nl', 'title' => 'LOCAL SAMPLE — NOT APPROVED',
                        'body' => 'LOCAL SAMPLE ONLY. Not approved legal content. {{legal_name}}', 'status' => AgreementTemplateStatus::Published, 'published_at' => now(), 'created_by' => $admin->id]);
                }
                $shop = $shop->fresh();
                $member->setRelation('webshop', $shop);
                $agreements = app(AgreementService::class);
                $template = $agreements->currentTemplate($shop);
                $agreements->accept($member, ['version' => $template->version, 'document_hash' => $agreements->hash($agreements->render($template, $shop)), 'signatory_name' => 'LOCAL SAMPLE Merchant']);
                $shop = app(WebshopLifecycle::class)->approve($admin, $shop->fresh(), 'LOCAL SAMPLE ONLY — synthetic integration fixture.', false);
                $member->setRelation('webshop', $shop);
                app(PublicProfileService::class)->save($member, ['slug' => 'local-sample-shop', 'display_name' => 'LOCAL SAMPLE Shop', 'short_description' => 'Synthetic local integration catalog.',
                    'categories' => ['home_living'], 'published' => true]);
                $offers = app(OfferService::class);
                $offer = $offers->transition($offers->create($member, OfferAcquisition::PromotionalCode, ['name' => 'LOCAL SAMPLE promotional voucher',
                    'discount_type' => 'fixed', 'discount_value' => 1000, 'redemption_validity_days' => 30]), OfferStatus::Active);
                for ($i = 0; $i < 10; $i++) {
                    $code = 'LOCAL-SAMPLE-'.Str::upper(Str::random(16));
                    VoucherCode::create(['webshop_id' => $shop->id, 'offer_id' => $offer->id, 'code' => $code,
                        'code_hash' => VoucherCode::hashFor($shop->id, $code), 'code_last4' => substr($code, -4)]);
                }
                $manifest = ['fixture' => 'shopbonus-local-v1', 'warning' => 'LOCAL SAMPLE ONLY. Never use these accounts or legal documents in production.', 'accounts' => $accounts,
                    'workspace' => $shop->public_id, 'offer_slug' => $offer->slug];
                $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                    throw new \RuntimeException('Cannot persist the private credentials file.');
                }
            });
            fclose($handle);
            $this->info('LOCAL SAMPLE fixtures created. Private credentials: '.$file);
            $this->warn('No live providers or email enabled. Admin MFA remains required. Sample legal documents are not production legal approval.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            fclose($handle);
            unlink($file);
            report($e);
            $this->error('Fixture setup failed; database changes rolled back. Check the local application log.');

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private static function body(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }
}
