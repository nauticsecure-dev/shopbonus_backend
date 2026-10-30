<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Affiliate\AffiliateNetworkRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateClick;
use App\Models\CookieConsent;
use App\Models\CookiePolicy;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Affiliate\ProgramSelector;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\PublicCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Visit webshop" (Trello #19 "Tracked consumer journey"). Records the click
 * and redirects through the selected network's own tracking link, with our
 * opaque click reference as the sub ID. A click never earns anything by
 * itself. Signed-in, verified consumers are attributed; anonymous visitors
 * are tracked but not attributed (no invented attribution).
 *
 * Marketing cookie consent is required for affiliate tracking. Without it the
 * visitor is sent to the merchant site untracked — never invent attribution.
 */
class VisitController extends Controller
{
    public function visit(Request $request, string $slug, PublicCatalog $catalog, ProgramSelector $selector, AuditLogger $audit): RedirectResponse
    {
        $webshopId = $catalog->listedWebshopId($slug) ?? throw new ApiException('This webshop is not available.', 404, 'webshop_not_found');
        $webshop = Webshop::findOrFail($webshopId);
        $profile = WebshopPublicProfile::where('webshop_id', $webshopId)->firstOrFail();
        $fallbackUrl = $profile->website_url ?? config('shopbonus.identity.frontend_url').'/webshops/'.$slug;
        $program = $selector->current($webshop);

        if ($program === null) {
            return redirect()->away($fallbackUrl);
        }

        // When a cookie policy is published, marketing consent is required for
        // affiliate tracking. Without a policy (local/tests) tracking may proceed.
        $policy = CookiePolicy::current();
        $consent = $policy === null ? null : $this->resolveMarketingConsent($request, $policy->version);
        if ($policy !== null && $consent === null) {
            $audit->recordSafely('affiliate.visit_untracked', metadata: [
                'webshop_id' => $webshopId,
                'reason' => 'marketing_consent_missing',
            ]);

            return redirect()->away($fallbackUrl);
        }

        /** @var User|null $user */
        $user = $request->user('sanctum');
        $attributable = $user !== null && $user->email_verified_at !== null && $user->isActive();
        $ttlDays = max(1, (int) config('shopbonus.affiliate.attribution_window_days', 30));

        $click = AffiliateClick::create([
            'reference' => Str::random(20),
            'user_id' => $attributable ? $user->id : null,
            'webshop_id' => $webshopId,
            'program_id' => $program->id,
            'ip_hash' => $audit->hashIp($request->ip()),
            'consent_id_hash' => $consent === null ? null : hash_hmac('sha256', 'cookie-consent|'.$consent->consent_id, (string) config('app.key')),
            'marketing_consent' => $consent !== null,
            'expires_at' => CarbonImmutable::now()->addDays($ttlDays),
        ]);

        return redirect()->away(
            app(AffiliateNetworkRegistry::class)->for($program->connection->network)->trackingUrl($program, $click->reference)
        );
    }

    private function resolveMarketingConsent(Request $request, string $policyVersion): ?CookieConsent
    {
        $consentId = $request->query('consent_id');
        if (! is_string($consentId) || $consentId === '' || strlen($consentId) > 64) {
            return null;
        }

        $latest = CookieConsent::where('consent_id', $consentId)->orderByDesc('id')->first();
        if ($latest === null || $latest->policy_version !== $policyVersion || empty($latest->choices['marketing'])) {
            return null;
        }

        return $latest;
    }
}
