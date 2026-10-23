<?php

namespace App\Http\Controllers\Api\V1\Answer24;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ContactOpportunity;
use App\Models\ContactSuppression;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\CurrentWorkspace;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Merchant side of Answer24 contact: the owner's lawful-basis attestation,
 * the do-not-contact list, and the queue of contact opportunities.
 */
class ContactSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(CurrentWorkspace $current): JsonResponse
    {
        return response()->json(['data' => $this->settings($current)]);
    }

    /**
     * Owner-only legal attestation (agreements.accept). Without it no order is
     * ever contacted. Legitimate interest is refused unless the platform
     * allows it (OPEN-MARKETING-CONSENT).
     */
    public function update(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'lawful_basis' => ['required_if:enabled,true', 'nullable', Rule::in(['consent_at_checkout', 'legitimate_interest'])],
            'attestation' => ['required_if:enabled,true', 'accepted_if:enabled,true'],
        ]);

        if (($data['lawful_basis'] ?? null) === 'legitimate_interest' && ! config('shopbonus.answer24.allow_legitimate_interest')) {
            throw new ApiException('Legitimate interest is not currently accepted as a basis for calls. Use consent captured at checkout.', 422, 'lawful_basis_not_permitted');
        }

        $webshop = $current->webshop();
        $settings = $data['enabled']
            ? ['enabled' => true, 'lawful_basis' => $data['lawful_basis'], 'documented_by' => $current->membership()->user->public_id, 'documented_at' => now()->toIso8601String()]
            : ['enabled' => false] + ($webshop->answer24_settings ?? []);
        $webshop->forceFill(['answer24_settings' => $settings])->save();

        $this->audit->record('answer24.settings_updated', category: AuditCategory::Workspace, resource: $webshop, webshopId: $webshop->id,
            metadata: ['enabled' => $settings['enabled'], 'lawful_basis' => $settings['lawful_basis'] ?? null]);

        return response()->json(['data' => $this->settings($current)]);
    }

    public function suppress(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:30'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $phone = isset($data['phone']) ? PhoneNumber::normalize($data['phone']) : null;
        if (isset($data['phone']) && $phone === null) {
            throw new ApiException('Enter a valid phone number.', 422, 'invalid_phone');
        }

        $added = 0;
        foreach (array_filter(['email' => isset($data['email']) ? ContactSuppression::emailHash($data['email']) : null, 'phone' => $phone !== null ? PhoneNumber::hash($phone) : null]) as $kind => $hash) {
            $added += ContactSuppression::firstOrCreate(
                ['webshop_id' => $current->webshop()->id, 'kind' => $kind, 'subject_hash' => $hash],
                ['reason' => $data['reason'] ?? null, 'source' => 'merchant', 'created_by' => $current->membership()->user_id],
            )->wasRecentlyCreated ? 1 : 0;
        }

        $this->audit->record('answer24.suppression_added', category: AuditCategory::Workspace, webshopId: $current->webshop()->id,
            metadata: ['kinds' => array_keys(array_filter(['email' => $data['email'] ?? null, 'phone' => $phone])), 'new' => $added]);

        return response()->json(['data' => ['suppressed' => true, 'new_entries' => $added]], $added > 0 ? 201 : 200);
    }

    public function opportunities(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $status = $request->validate(['status' => ['sometimes', 'string', 'max:32']])['status'] ?? null;

        $page = ContactOpportunity::where('webshop_id', $current->webshop()->id)
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->withCount(['links as active_orders_count' => fn ($q) => $q->where('status', 'active')])
            ->latest('id')->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (ContactOpportunity $o) => [
                'id' => $o->public_id, 'status' => $o->status, 'lawful_basis' => $o->lawful_basis,
                'orders' => $o->getAttribute('active_orders_count'),
                'window_ends_at' => $o->window_ends_at->toIso8601String(),
                'ready_at' => $o->ready_at?->toIso8601String(), 'dispatched_at' => $o->dispatched_at?->toIso8601String(),
                'cancelled_at' => $o->cancelled_at?->toIso8601String(), 'cancel_reason' => $o->cancel_reason,
                'created_at' => $o->created_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'dispatch_enabled' => (bool) config('shopbonus.answer24.dispatch_enabled')],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(CurrentWorkspace $current): array
    {
        $s = $current->webshop()->answer24_settings ?? [];

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'lawful_basis' => $s['lawful_basis'] ?? null,
            'documented_by' => $s['documented_by'] ?? null,
            'documented_at' => $s['documented_at'] ?? null,
            'allowed_bases' => config('shopbonus.answer24.allow_legitimate_interest') ? ['consent_at_checkout', 'legitimate_interest'] : ['consent_at_checkout'],
            'platform_dispatch_enabled' => (bool) config('shopbonus.answer24.dispatch_enabled'),
            'notes' => 'An order alone never authorises contact. Consent-based contact requires the shop to report contact consent per order.',
        ];
    }
}
