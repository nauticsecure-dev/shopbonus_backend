<?php

namespace App\Services\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Models\PlatformSettingVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Public metadata only. Legal/CMS/consent, financial policies and provider secrets retain their existing owners. */
class PlatformSettings
{
    public const KEYS = ['public.brand_name', 'public.support_email', 'public.languages'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function draft(User $actor, string $key, mixed $value, string $reason, ?PlatformSettingVersion $rollback = null): PlatformSettingVersion
    {
        $this->validateValue($key, $value);

        return DB::transaction(function () use ($actor, $key, $value, $reason, $rollback) {
            DB::table('platform_settings')->insertOrIgnore(['key' => $key, 'last_version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $setting = DB::table('platform_settings')->where('key', $key)->lockForUpdate()->first();
            $version = PlatformSettingVersion::create(['setting_id' => $setting->id, 'version' => $setting->last_version + 1,
                'value' => $value, 'checksum' => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)), 'status' => 'draft',
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
            if (! hash_equals($version->checksum, hash('sha256', json_encode($version->value, JSON_THROW_ON_ERROR)))) {
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

    private function validateValue(string $key, mixed $value): void
    {
        Validator::make(['key' => $key], ['key' => ['required', Rule::in(self::KEYS)]])->validate();
        $rules = match ($key) {
            'public.brand_name' => ['value' => ['required', 'string', 'min:1', 'max:100', 'regex:/^[\pL\pN .&\'-]+$/u']],
            'public.support_email' => ['value' => ['required', 'email:rfc', 'max:254']],
            'public.languages' => ['value' => ['required', 'array', 'min:1', 'max:2'], 'value.*' => ['required', 'string', 'distinct', Rule::in(User::SUPPORTED_LOCALES)]],
            default => throw new \LogicException('Unexpected validated setting key.'),
        };
        Validator::make(['value' => $value], $rules)->validate();
    }

    /** @return array<string, mixed> */
    public function published(): array
    {
        $values = ['public.brand_name' => 'Shopbonus', 'public.support_email' => null, 'public.languages' => User::SUPPORTED_LOCALES];
        $versions = [];
        foreach (DB::table('platform_settings')->whereIn('key', self::KEYS)->whereNotNull('published_version_id')->get() as $setting) {
            $version = PlatformSettingVersion::findOrFail($setting->published_version_id);
            $values[$setting->key] = $version->value;
            $versions[$setting->key] = $version->public_id;
        }

        return ['values' => $values, 'versions' => $versions];
    }
}
