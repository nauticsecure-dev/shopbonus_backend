<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\PlatformSettingVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\NotificationPolicy;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Support\SupportPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Versioned public metadata and bounded operational limits. Sensitive business/provider policies retain their existing owners. */
class PlatformSettings
{
    public const PUBLIC_KEYS = ['public.brand_name', 'public.support_email', 'public.languages'];

    public const KEYS = [...self::PUBLIC_KEYS, 'operations.diagnostics_daily_request_limit', 'support.ai', 'notifications.policy',
        'notifications.template.account_welcome.nl',
        'notifications.template.account_welcome.en',
        'notifications.template.email_verified.nl',
        'notifications.template.email_verified.en',
        'notifications.template.cashback_credited.nl',
        'notifications.template.cashback_credited.en',
        'notifications.template.voucher_received.nl',
        'notifications.template.voucher_received.en',
        'notifications.template.voucher_expiring.nl',
        'notifications.template.voucher_expiring.en',
        'notifications.template.voucher_used.nl',
        'notifications.template.voucher_used.en',
        'notifications.template.voucher_cancelled.nl',
        'notifications.template.voucher_cancelled.en',
        'notifications.template.cashback_adjusted.nl',
        'notifications.template.cashback_adjusted.en',
        'notifications.template.support_reply.nl',
        'notifications.template.support_reply.en',
        'notifications.template.favorite_merchant_offer.nl',
        'notifications.template.favorite_merchant_offer.en',
        'notifications.template.workspace_approved.nl',
        'notifications.template.workspace_approved.en',
        'notifications.template.workspace_rejected.nl',
        'notifications.template.workspace_rejected.en',
        'notifications.template.offer_published.nl',
        'notifications.template.offer_published.en',
        'notifications.template.offer_expiring.nl',
        'notifications.template.offer_expiring.en',
        'notifications.template.commission_changed.nl',
        'notifications.template.commission_changed.en',
        'notifications.template.integration_attention.nl',
        'notifications.template.integration_attention.en',
        'notifications.template.workspace_support_reply.nl',
        'notifications.template.workspace_support_reply.en',
        'notifications.template.workspace_review_required.nl',
        'notifications.template.workspace_review_required.en',
        'notifications.template.ai_support_escalated.nl',
        'notifications.template.ai_support_escalated.en',
        'notifications.template.affiliate_import_failed.nl',
        'notifications.template.affiliate_import_failed.en',
        'notifications.template.notification_delivery_failed.nl',
        'notifications.template.notification_delivery_failed.en',
        'notifications.template.critical_notification_failure.nl',
        'notifications.template.critical_notification_failure.en',
        'notifications.template.merchant_attention.nl',
        'notifications.template.merchant_attention.en',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function draft(User $actor, string $key, mixed $value, string $reason, ?PlatformSettingVersion $rollback = null): PlatformSettingVersion
    {
        $this->validateValue($key, $value);

        return DB::transaction(function () use ($actor, $key, $value, $reason, $rollback) {
            DB::table('platform_settings')->insertOrIgnore(['key' => $key, 'last_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $setting = DB::table('platform_settings')->where('key', $key)->lockForUpdate()->first();
            $version = PlatformSettingVersion::create(['setting_id' => $setting->id, 'version' => $setting->last_version + 1,
                'value' => $value, 'checksum' => self::checksum($value), 'status' => 'draft',
                'created_by' => $actor->id, 'reason' => $reason, 'rollback_of_id' => $rollback?->id]);
            DB::table('platform_settings')->where('id', $setting->id)->update(['last_version' => $version->version, 'updated_at' => now()]);
            $this->audit->record('admin.setting_drafted', category: AuditCategory::Admin, resource: $version,
                actorUserId: $actor->id, metadata: ['key' => $key, 'version' => $version->version, 'checksum' => $version->checksum, 'rollback_of' => $rollback?->public_id]);

            return $version;
        }, 3);
    }

    public function submit(PlatformSettingVersion $version, User $actor): PlatformSettingVersion
    {
        return DB::transaction(function () use ($version, $actor) {
            $version = PlatformSettingVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($version->status !== 'draft') {
                throw ApiException::conflict('Only draft settings can be submitted for review.', 'setting_not_draft');
            }
            $version->forceFill(['status' => 'review'])->save();
            $this->audit->record('admin.setting_submitted', category: AuditCategory::Admin, resource: $version, actorUserId: $actor->id);

            return $version;
        });
    }

    public function publish(PlatformSettingVersion $version, User $actor, string $note): PlatformSettingVersion
    {
        return DB::transaction(function () use ($version, $actor, $note) {
            $setting = DB::table('platform_settings')->where('id', $version->setting_id)->lockForUpdate()->first();
            $version = PlatformSettingVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($version->created_by === $actor->id) {
                throw new ApiException('A different administrator must publish this setting.', 403, 'second_admin_required');
            }
            if ($version->status !== 'review') {
                throw ApiException::conflict('Only reviewed settings can be published.', 'setting_not_in_review');
            }
            if ($setting->published_version_id !== null && PlatformSettingVersion::findOrFail($setting->published_version_id)->version > $version->version) {
                throw ApiException::conflict('A newer version is already published. Create a rollback draft instead.', 'setting_stale');
            }
            $this->validateValue($setting->key, $version->value);
            if ($setting->key === 'support.ai') {
                $test = DB::table('support_policy_tests')->where('version_id', $version->id)->where('checksum', $version->checksum)->first();
                $report = $test === null ? null : json_decode($test->report, true);
                if (! is_array($report) || ($report['failed'] ?? 1) !== 0 || ($report['total'] ?? 0) < 100
                    || ($report['corpus_fingerprint'] ?? null) !== SupportPolicy::regressionFingerprint()) {
                    throw ApiException::conflict('Test the support policy before publication.', 'support_policy_test_required');
                }
            }
            if (str_starts_with($setting->key, 'notifications.template.')) {
                $test = DB::table('notification_template_tests')->where('version_id', $version->id)->where('checksum', $version->checksum)->first();
                if ($test === null || (json_decode($test->report, true)['failed'] ?? 1) !== 0) {
                    throw ApiException::conflict('Test the notification template before publication.', 'notification_template_test_required');
                }
            }
            if (! hash_equals($version->checksum, self::checksum($version->value))) {
                throw ApiException::conflict('Setting version integrity verification failed.', 'setting_integrity_failed');
            }
            $version->forceFill(['status' => 'published', 'reviewed_by' => $actor->id, 'review_note' => $note, 'published_at' => now()])->save();
            DB::table('platform_settings')->where('id', $setting->id)->update(['published_version_id' => $version->id, 'updated_at' => now()]);
            $this->audit->record('admin.setting_published', category: AuditCategory::Admin, resource: $version, actorUserId: $actor->id,
                metadata: ['key' => $setting->key, 'previous_version' => $setting->published_version_id === null ? null : PlatformSettingVersion::findOrFail($setting->published_version_id)->public_id,
                    'new_version' => $version->public_id, 'checksum' => $version->checksum, 'note' => $note]);

            return $version;
        }, 3);
    }

    public function rollback(PlatformSettingVersion $version, User $actor, string $reason): PlatformSettingVersion
    {
        if ($version->status !== 'published') {
            throw ApiException::conflict('Only published history can be used for a rollback draft.', 'setting_not_published');
        }
        $key = DB::table('platform_settings')->where('id', $version->setting_id)->value('key');

        return $this->draft($actor, $key, $version->value, $reason, $version);
    }

    /** Canonical object keys: MySQL JSON does not preserve insertion order. Lists retain their semantic order. */
    public static function checksum(mixed $value): string
    {
        $normalize = function (mixed $v) use (&$normalize): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($normalize, $v);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }

    private function validateValue(string $key, mixed $value): void
    {
        Validator::make(['key' => $key], ['key' => ['required', Rule::in(self::KEYS)]])->validate();
        $rules = match ($key) {
            'support.ai' => SupportPolicy::rules(),
            'notifications.policy' => NotificationPolicy::rules(),
            'operations.diagnostics_daily_request_limit' => ['value' => ['required', 'integer:strict', 'min:0', 'max:1000']],
            'public.brand_name' => ['value' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\pL\pN .&\'-]+$/u']],
            'public.support_email' => ['value' => ['required', 'email:rfc', 'max:254']],
            'public.languages' => ['value' => ['required', 'array', 'min:1', 'max:2'], 'value.*' => ['required', 'string', 'distinct', Rule::in(User::SUPPORTED_LOCALES)]],
            default => str_starts_with($key, 'notifications.template.') ? NotificationTemplates::rules() : throw new \LogicException('Unexpected validated setting key.'),
        };
        Validator::make(['value' => $value], $rules)->validate();
        if (str_starts_with($key, 'notifications.template.')) {
            NotificationTemplates::validate($key, $value);
        }
    }

    /** @return array<string, mixed> */
    public function published(bool $publicOnly = true): array
    {
        $values = ['public.brand_name' => 'Shopbonus', 'public.support_email' => null, 'public.languages' => User::SUPPORTED_LOCALES];
        if (! $publicOnly) {
            $values['operations.diagnostics_daily_request_limit'] = max(0, min(1000, (int) config('diagnostics.daily_request_limit')));
        }
        $versions = [];
        foreach (DB::table('platform_settings')->whereIn('key', $publicOnly ? self::PUBLIC_KEYS : self::KEYS)->whereNotNull('published_version_id')->get() as $setting) {
            $version = PlatformSettingVersion::findOrFail($setting->published_version_id);
            $values[$setting->key] = $version->value;
            $versions[$setting->key] = $version->public_id;
        }

        return ['values' => $values, 'versions' => $versions];
    }

    public function diagnosticDailyLimit(): int
    {
        $cap = max(0, min(1000, (int) config('diagnostics.daily_request_limit')));
        $setting = DB::table('platform_settings')->where('key', 'operations.diagnostics_daily_request_limit')->first();
        if ($setting === null || $setting->published_version_id === null) {
            return $cap;
        }
        $value = PlatformSettingVersion::findOrFail($setting->published_version_id)->value;

        return is_int($value) ? max(0, min($cap, $value)) : 0;
    }
}
