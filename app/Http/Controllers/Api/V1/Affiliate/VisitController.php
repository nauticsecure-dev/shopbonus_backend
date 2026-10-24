<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Affiliate\AffiliateNetworkRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateClick;
use App\Models\User;
use App\Models\Webshop;
use App\Models\WebshopPublicProfile;
use App\Services\Affiliate\ProgramSelector;
use App\Services\Audit\AuditLogger;
use App\Services\Discovery\PublicCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Visit webshop" (Trello #19 "Tracked consumer journey"). Records the click
 * and redirects through the selected network's own tracking link, with our
 * opaque click reference as the sub ID. A click never earns anything by
 * itself. Signed-in, verified consumers are attributed; anonymous visitors
 * are tracked but not attributed (no invented attribution).
 */
class VisitController extends Controller
{
    public function visit(Request $request, string $slug, PublicCatalog $catalog, ProgramSelector $selector, AuditLogger $audit): RedirectResponse
    {
        $webshopId = $catalog->listedWebshopId($slug) ?? throw new ApiException('This webshop is not available.', 404, 'webshop_not_found');
        $webshop = Webshop::findOrFail($webshopId);
        $profile = WebshopPublicProfile::where('webshop_id', $webshopId)->firstOrFail();
        $program = $selector->current($webshop);

        if ($program === null) {
            // Not available through a network: plain link, nothing tracked.
            return redirect()->away($profile->website_url ?? config('shopbonus.identity.frontend_url').'/webshops/'.$slug);
        }

        /** @var User|null $user */
        $user = $request->user('sanctum');
        $attributable = $user !== null && $user->email_verified_at !== null && $user->isActive();

        $click = AffiliateClick::create([
            'reference' => Str::random(20), 'user_id' => $attributable ? $user->id : null, 'webshop_id' => $webshopId, 'program_id' => $program->id,
            'ip_hash' => $audit->hashIp($request->ip()),
        ]);

        return redirect()->away(app(AffiliateNetworkRegistry::class)->for($program->connection->network)->trackingUrl($program, $click->reference));
    }
}
