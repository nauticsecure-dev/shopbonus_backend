<?php

namespace App\Services\Notifications;

use App\Models\PlatformSettingVersion;
use App\Services\Admin\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class NotificationPolicy
{
    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return ['value' => ['required', 'array:retention_days,email_enabled,voucher_reminder_days,offer_expiry_days'], 'value.retention_days' => ['required', 'integer:strict', 'between:1,365'], 'value.email_enabled' => ['required', 'boolean:strict'],
            'value.voucher_reminder_days' => ['sometimes', 'array', 'list', 'min:1', 'max:5'],
            'value.voucher_reminder_days.*' => ['required', 'integer:strict', 'distinct:strict', 'between:1,90'],
            'value.offer_expiry_days' => ['sometimes', 'integer:strict', 'between:1,30']];
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        $id = DB::table('platform_settings')->where('key', 'notifications.policy')->value('published_version_id');

        $defaults = ['retention_days' => max(1, min(365, (int) config('notifications.retention_days'))), 'email_enabled' => true,
            'voucher_reminder_days' => [30, 7, 1], 'offer_expiry_days' => 7];
        if ($id === null) {
            return $defaults;
        }
        $version = PlatformSettingVersion::findOrFail($id);
        if ($version->status !== 'published' || ! hash_equals($version->checksum, PlatformSettings::checksum($version->value))) {
            throw new \LogicException('Notification policy integrity verification failed.');
        }
        Validator::make(['value' => $version->value], self::rules())->validate();

        return array_replace($defaults, $version->value);
    }

    public function retentionDays(): int
    {
        return (int) $this->current()['retention_days'];
    }

    public function emailEnabled(): bool
    {
        return config('notifications.email_enabled') && (bool) $this->current()['email_enabled'];
    }

    /** @return list<int> */
    public function reminderDays(): array
    {
        return $this->current()['voucher_reminder_days'];
    }

    public function offerExpiryDays(): int
    {
        return $this->current()['offer_expiry_days'];
    }
}
