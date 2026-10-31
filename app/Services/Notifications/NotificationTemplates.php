<?php

namespace App\Services\Notifications;

use App\Exceptions\ApiException;
use App\Models\CentralNotification;
use App\Models\PlatformSettingVersion;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Audit\AuditLogger;
use App\Support\FrontendPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NotificationTemplates
{
    /** Placeholders every template may use; the verified amount only where an amount exists. */
    public const VARIABLES = ['webshop_name', 'first_name'];

    public static function key(string $event, string $locale): string
    {
        return 'notifications.template.'.$event.'.'.$locale;
    }

    /** @return array<string, array<mixed>> */
    public static function rules(): array
    {
        return ['value' => ['required', 'array:title,body,action_label'], 'value.title' => ['required', 'string', 'max:70'],
            'value.body' => ['required', 'string', 'max:180'], 'value.action_label' => ['required', 'string', 'max:40']];
    }

    /** @param array<string, mixed> $value */
    public static function validate(string $key, array $value): void
    {
        Validator::make(['value' => $value], self::rules())->validate();
        $event = explode('.', $key)[2] ?? '';
        foreach ($value as $text) {
            preg_match_all('/\{\{([a-z_]+)\}\}/', $text, $matches);
            $allowed = [...self::VARIABLES, ...($event === 'cashback_credited' ? ['amount_formatted'] : [])];
            if (array_diff($matches[1], $allowed) !== [] || preg_match('/[{}<>\x00-\x1f\x7f\d€$@]|https?:|www\.|openai|chatgpt/iu', preg_replace('/\{\{[a-z_]+\}\}/', '', $text))) {
                throw ValidationException::withMessages(['value' => 'Use approved placeholders and plain text without literal amounts, deadlines, links or provider references.']);
            }
        }
        if ($event === 'cashback_credited' && ! str_contains(implode(' ', $value), '{{amount_formatted}}')) {
            throw ValidationException::withMessages(['value.body' => 'The verified amount placeholder is required.']);
        }
    }

    public function find(string $id): PlatformSettingVersion
    {
        $v = PlatformSettingVersion::where('public_id', strtolower($id))->first() ?? throw new ApiException('Template not found.', 404, 'not_found');
        $key = DB::table('platform_settings')->where('id', $v->setting_id)->value('key');
        if (! str_starts_with($key, 'notifications.template.')) {
            throw new ApiException('Template not found.', 404, 'not_found');
        }
        if (! hash_equals($v->checksum, PlatformSettings::checksum($v->value))) {
            throw ApiException::conflict('Template integrity check failed.', 'template_integrity_failed');
        }

        return $v;
    }

    /** @return array<string, mixed> */
    public function present(PlatformSettingVersion $v): array
    {
        $s = DB::table('platform_settings')->where('id', $v->setting_id)->first();

        return ['id' => $v->public_id, 'key' => $s->key, 'version' => $v->version, 'status' => $v->status, 'value' => $v->value,
            'checksum' => $v->checksum, 'is_current' => (int) ($s->published_version_id ?? 0) === $v->id,
            'published_at' => $v->published_at?->toIso8601String(), 'rollback_of' => $v->rollback_of_id === null ? null : PlatformSettingVersion::findOrFail($v->rollback_of_id)->public_id];
    }

    /** @return array<string, mixed> */
    public function test(PlatformSettingVersion $v, User $actor): array
    {
        if (! in_array($v->status, ['draft', 'review'], true)) {
            throw ApiException::conflict('Only unpublished templates can be tested.', 'template_not_draft');
        }
        $key = DB::table('platform_settings')->where('id', $v->setting_id)->value('key');
        self::validate($key, $v->value);
        $locale = str_ends_with($key, '.nl') ? 'nl' : 'en';
        $preview = $this->substitute($v->value, $locale === 'nl' ? '€7,00' : '€7.00', ['webshop_name' => 'Woon & Co', 'first_name' => 'Anna']);
        $n = new CentralNotification;
        $n->data = [...$preview, 'locale' => $locale, 'action_route' => FrontendPath::to('consumer_benefits')];
        $render = app(NotificationMail::class)->render($n);
        $report = ['failed' => 0, 'checks' => ['schema', 'variables', 'lengths', 'plain_text', 'html_render', 'text_render'], 'preview' => $preview, 'synthetic' => true,
            'render_sha256' => hash('sha256', $render['html'].'|'.$render['text'])];
        DB::table('notification_template_tests')->updateOrInsert(['version_id' => $v->id], ['checksum' => $v->checksum, 'report' => json_encode($report, JSON_THROW_ON_ERROR), 'tested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('notifications.template_tested', resource: $v, actorUserId: $actor->id);

        return $report;
    }

    /** @param array<string, string> $value
     * @return array<string, string>
     */
    private function substitute(array $value, string $amount, array $variables = []): array
    {
        $map = ['{{amount_formatted}}' => $amount];
        foreach (self::VARIABLES as $name) {
            // Names are user-entered: plain text only, bounded, no markup or placeholder syntax.
            $map['{{'.$name.'}}'] = mb_substr(trim((string) preg_replace('/[<>{}\x00-\x1f\x7f]/u', '', (string) ($variables[$name] ?? ''))), 0, 60);
        }

        return array_map(fn ($text) => trim((string) preg_replace('/ {2,}/', ' ', strtr($text, $map))), $value);
    }

    /** @return array<string, mixed> */
    /** @param array<string, string|null> $variables */
    public function resolve(string $event, string $locale, ?string $amount = null, array $variables = []): array
    {
        foreach (array_unique([$locale, 'nl']) as $candidate) {
            $s = DB::table('platform_settings')->where('key', self::key($event, $candidate))->first();
            if ($s?->published_version_id !== null) {
                $v = PlatformSettingVersion::findOrFail($s->published_version_id);
                self::validate($s->key, $v->value);
                if ($v->status !== 'published') {
                    throw new \LogicException('Template is not published.');
                }
                if ($candidate !== $locale) {
                    DB::table('notification_template_fallbacks')->insertOrIgnore(['event_type' => $event, 'requested_locale' => $locale, 'occurrences' => 0]);
                    DB::table('notification_template_fallbacks')->where('event_type', $event)->where('requested_locale', $locale)->increment('occurrences');
                }
                $amount = $candidate === 'nl' ? str_replace(['.', ' points'], [',', ' punten'], $amount ?? '') : str_replace([',', ' punten'], ['.', ' points'], $amount ?? '');
                if (! hash_equals($v->checksum, PlatformSettings::checksum($v->value))) {
                    throw new \LogicException('Published template integrity failure.');
                }

                return [...$this->substitute($v->value, $amount, $variables), 'template_version' => $v->public_id, 'locale' => $candidate, 'locale_fallback' => $candidate !== $locale];
            }
        }

        return [...NotificationCatalogue::content($event, $locale, $amount), 'template_version' => 'builtin-'.$event.'-v1', 'locale' => $locale, 'locale_fallback' => false];
    }
}
