<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\LegalAcceptance;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WebshopMembership;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Data rights (Trello #10 §9, #18 "Privacy/data-rights options"). The export
 * contains only the requester's own data. A deletion request is recorded,
 * not executed: ledger, voucher and audit evidence must be retained (D-013),
 * so processing is a controlled admin step (B16).
 */
class PrivacyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function export(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->public_id, 'first_name' => $user->first_name, 'email' => $user->email, 'phone' => $user->phone,
                'locale' => $user->locale, 'status' => $user->status->value, 'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(), 'last_login_at' => $user->last_login_at?->toIso8601String(),
                'has_profile_photo' => $user->avatar_path !== null,
            ],
            'consents' => ConsentRecord::where('user_id', $user->id)->orderBy('id')->get()->map(fn (ConsentRecord $c) => [
                'purpose' => $c->purpose->value, 'granted' => $c->granted, 'policy_version' => $c->policy_version, 'source' => $c->source, 'recorded_at' => $c->recorded_at->toIso8601String(),
            ])->all(),
            'legal_acceptances' => LegalAcceptance::where('user_id', $user->id)->orderBy('id')->get()->map(fn (LegalAcceptance $a) => [
                'document' => $a->document_type->value, 'version' => $a->document_version, 'accepted_at' => $a->accepted_at->toIso8601String(),
            ])->all(),
            'vouchers' => Voucher::with('code')->where('consumer_user_id', $user->id)->orderBy('id')->get()->map(fn (Voucher $v) => [
                'id' => $v->public_id, 'status' => $v->status->value, 'origin' => $v->origin->value, 'code' => $v->code->code,
                'title' => $v->terms_snapshot['name'] ?? null, 'issued_at' => $v->issued_at?->toIso8601String(), 'redeemed_at' => $v->redeemed_at?->toIso8601String(),
            ])->all(),
            'favorite_webshops' => DB::table('consumer_favorite_webshops')->join('webshops', 'webshops.id', '=', 'consumer_favorite_webshops.webshop_id')
                ->where('user_id', $user->id)->orderBy('webshops.name')->pluck('webshops.name')->all(),
            'preferred_categories' => DB::table('consumer_category_preferences')->where('user_id', $user->id)->orderBy('category')->pluck('category')->all(),
            'workspace_memberships' => WebshopMembership::with('webshop:id,name')->where('user_id', $user->id)->get()
                ->map(fn (WebshopMembership $m) => ['webshop' => $m->webshop->name, 'role' => $m->role->value, 'status' => $m->status->value])->all(),
            'privacy_requests' => PrivacyRequest::where('user_id', $user->id)->orderBy('id')->get(['public_id', 'type', 'status', 'requested_at', 'completed_at'])->toArray(),
        ];

        $this->audit->recordSafely('consumer.data_exported', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);

        return response()->json(['data' => $data], 200, [
            'Content-Disposition' => 'attachment; filename="shopbonus-data-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function requestDeletion(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);

        /** @var User $user */
        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password')]);
        }

        $privacyRequest = DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first();
            if (PrivacyRequest::where('user_id', $user->id)->where('type', 'deletion')->where('status', 'open')->exists()) {
                return null;
            }

            $created = PrivacyRequest::create(['user_id' => $user->id, 'type' => 'deletion', 'status' => 'open', 'requested_at' => now()]);
            $this->audit->record('consumer.deletion_requested', category: AuditCategory::Identity, resource: $user, actorUserId: $user->id);

            return $created;
        });

        if ($privacyRequest === null) {
            throw ApiException::conflict('A deletion request is already being processed.', 'deletion_already_requested');
        }

        return response()->json(['data' => $this->present($privacyRequest) + [
            'message' => 'Your request was recorded and will be handled by Shopbonus. Records we must keep by law (vouchers, payments, audit) are retained for their legal period.',
        ]], 202);
    }

    public function requests(Request $request): JsonResponse
    {
        return response()->json(['data' => PrivacyRequest::where('user_id', $request->user()->id)->orderByDesc('id')->get()
            ->map(fn (PrivacyRequest $r) => $this->present($r))->all()]);
    }

    /** @return array<string, mixed> */
    private function present(PrivacyRequest $r): array
    {
        return ['id' => $r->public_id, 'type' => $r->type, 'status' => $r->status, 'requested_at' => $r->requested_at->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String()];
    }
}
