<?php

namespace App\Http\Controllers\Api\V1\Content;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CookieConsent;
use App\Models\CookiePolicy;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Consent\CookieConsents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cookie banner backend for visitors (Trello #5). The browser keeps the
 * opaque consent ID; the server keeps the append-only history.
 */
class CookieConsentController extends Controller
{
    public function policy(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['sometimes', Rule::in(['nl', 'en'])]]);
        $locale = $data['locale'] ?? 'nl';
        $policy = CookiePolicy::current();

        return response()->json(['data' => $policy === null
            ? ['version' => null, 'categories' => [['key' => 'necessary', 'required' => true, 'trackers' => []]], 'texts' => null, 'requires_reconsent' => false]
            : self::present($policy, $locale)], 200, ['Cache-Control' => 'public, max-age=60']);
    }

    public function store(Request $request, CookieConsents $consents, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'consent_id' => ['sometimes', 'nullable', 'uuid'],
            'policy_version' => ['required', 'string', 'max:32'],
            'action' => ['required', Rule::in(['accept_all', 'reject_optional', 'customize', 'withdraw'])],
            'choices' => ['required_if:action,customize', 'array:preferences,analytics,marketing'],
            'choices.*' => ['boolean'],
        ]);
        /** @var User|null $user */
        $user = $request->user('sanctum');
        $result = $consents->record($data['consent_id'] ?? null, $data['policy_version'], $data['action'], $data['choices'] ?? [], $user?->id, $audit->hashIp($request->ip()));

        return response()->json(['data' => self::consent($result['record']) + ['allowed_trackers' => $result['allowed_trackers']]], 201);
    }

    public function show(string $consentId): JsonResponse
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $consentId) !== 1) {
            throw new ApiException('Consent not found.', 404, 'not_found');
        }
        $latest = CookieConsent::where('consent_id', $consentId)->latest('id')->first() ?? throw new ApiException('Consent not found.', 404, 'not_found');
        $policy = CookiePolicy::current();

        return response()->json(['data' => self::consent($latest) + [
            'current_policy_version' => $policy?->version,
            'needs_renewal' => $policy !== null && $policy->version !== $latest->policy_version && $policy->requires_reconsent,
            'allowed_trackers' => $policy === null || $policy->version !== $latest->policy_version && $policy->requires_reconsent ? [] : app(CookieConsents::class)->allowedTrackers($policy, $latest->choices),
        ]]);
    }

    /** @return array<string, mixed> */
    public static function present(CookiePolicy $p, string $locale): array
    {
        return [
            'version' => $p->version, 'requires_reconsent' => $p->requires_reconsent, 'published_at' => $p->published_at?->toIso8601String(),
            'texts' => $p->texts[$locale] ?? $p->texts['nl'],
            'categories' => array_map(fn ($c) => ['key' => $c['key'], 'required' => $c['required'], 'trackers' => array_map(fn ($t) => [
                'key' => $t['key'], 'name' => $t['name'], 'provider' => $t['provider'], 'purpose' => $t['purpose_'.$locale] ?? $t['purpose_nl'], 'retention' => $t['retention'],
            ], $c['trackers'])], $p->categories),
        ];
    }

    /** @return array<string, mixed> */
    private static function consent(CookieConsent $c): array
    {
        return ['consent_id' => $c->consent_id, 'policy_version' => $c->policy_version, 'action' => $c->action, 'choices' => $c->choices, 'recorded_at' => $c->created_at->toIso8601String()];
    }
}
