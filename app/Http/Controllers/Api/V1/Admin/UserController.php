<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\MembershipStatus;
use App\Enums\PlatformRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Webshop;
use App\Models\WebshopMembership;
use App\Services\Admin\UserAdministration;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Super Admin user search and account actions (Trello #21 "Users,
 * memberships & access"). Never returns password hashes, 2FA secrets or
 * tokens; passwords are never set here.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserAdministration $users) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', Rule::in(['consumer', 'merchant', 'owner', 'manager', 'employee', 'super_admin'])],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'webshop_id' => ['sometimes', 'string', 'max:26'],
            'registered_from' => ['sometimes', 'date_format:Y-m-d'],
            'registered_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:registered_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($data['search']) ? '%'.addcslashes(Str::lower($data['search']), '%_\\').'%' : null;
        $activeMembership = fn ($q) => $q->where('status', MembershipStatus::Active);

        $page = User::query()
            ->with(['platformRoles' => fn ($q) => $q->whereNull('revoked_at'), 'memberships' => fn ($q) => $q->with('webshop:id,public_id,name')])
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('email', 'like', $search)->orWhere('first_name', 'like', $search)))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(($data['role'] ?? null) === 'super_admin', fn ($q) => $q->whereHas('platformRoles', fn ($r) => $r->where('role', PlatformRole::SuperAdmin)->whereNull('revoked_at')))
            ->when(($data['role'] ?? null) === 'consumer', fn ($q) => $q->whereDoesntHave('memberships', $activeMembership)->whereDoesntHave('platformRoles', fn ($r) => $r->whereNull('revoked_at')))
            ->when(($data['role'] ?? null) === 'merchant', fn ($q) => $q->whereHas('memberships', $activeMembership))
            ->when(in_array($data['role'] ?? null, ['owner', 'manager', 'employee'], true), fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('status', MembershipStatus::Active)->where('role', $data['role'])))
            ->when($data['webshop_id'] ?? null, fn ($q, $w) => $q->whereHas('memberships', fn ($m) => $m->where('webshop_id', Webshop::where('public_id', Str::lower($w))->value('id') ?? 0)))
            ->when($data['registered_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($data['registered_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (User $u) => self::summary($u))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $user, AuditLogger $audit): JsonResponse
    {
        $model = $this->find($user)->load(['platformRoles', 'memberships.webshop:id,public_id,name,status']);
        $audit->recordSafely('admin.user_viewed', category: AuditCategory::Admin, resource: $model);

        return response()->json(['data' => self::summary($model) + [
            'last_login_at' => $model->last_login_at?->toIso8601String(),
            'locale' => $model->preferredLocale(),
            'platform_roles' => $model->platformRoles->map(fn ($r) => ['role' => $r->role->value, 'granted_via' => $r->granted_via,
                'granted_at' => $r->granted_at->toIso8601String(), 'revoked_at' => $r->revoked_at?->toIso8601String()])->values()->all(),
            'memberships' => $model->memberships->map(fn (WebshopMembership $m) => [
                'webshop' => ['id' => $m->webshop->public_id, 'name' => $m->webshop->name, 'status' => $m->webshop->status->value],
                'role' => $m->role->value, 'status' => $m->status->value, 'joined_at' => $m->joined_at?->toIso8601String(),
            ])->values()->all(),
            'wallets' => Wallet::where('owner_type', 'user')->where('owner_id', $model->id)->get()->map(fn (Wallet $w) => [
                'id' => $w->public_id, 'unit' => $w->unit->value, 'available' => $w->available, 'reserved' => $w->reserved, 'pending' => $w->pending,
            ])->all(),
            'api_tokens' => $model->tokens()->count(),
        ]]);
    }

    public function suspend(Request $request, string $user): JsonResponse
    {
        return $this->respond($this->users->suspend($request->user(), $this->find($user), $this->reason($request)));
    }

    public function reactivate(Request $request, string $user): JsonResponse
    {
        return $this->respond($this->users->reactivate($request->user(), $this->find($user), $this->reason($request)));
    }

    public function resetSecurity(Request $request, string $user): JsonResponse
    {
        return $this->respond($this->users->resetSecurity($request->user(), $this->find($user), $this->reason($request)));
    }

    public function revokeSuperAdmin(Request $request, string $user): JsonResponse
    {
        return $this->respond($this->users->revokeSuperAdmin($request->user(), $this->find($user), $this->reason($request)));
    }

    public function revokeMembership(Request $request, string $webshop, string $user, TeamService $team): JsonResponse
    {
        $reason = $this->reason($request);
        $shop = Webshop::where('public_id', Str::lower($webshop))->first() ?? throw new NotFoundHttpException;
        $membership = WebshopMembership::where('webshop_id', $shop->id)->where('user_id', $this->find($user)->id)->first() ?? throw new NotFoundHttpException;

        $team->adminRevoke($request->user(), $membership, $reason);

        return response()->json(['data' => ['webshop_id' => $shop->public_id, 'user_id' => $user, 'role' => $membership->role->value, 'status' => $membership->status->value]]);
    }

    /** @return array<string, mixed> */
    public static function summary(User $u): array
    {
        return [
            'id' => $u->public_id,
            'first_name' => $u->first_name,
            'email' => $u->email,
            'email_verified' => $u->email_verified_at !== null,
            'status' => $u->status->value,
            'onboarding_intent' => $u->onboarding_intent->value,
            'roles' => $u->platformRoles->whereNull('revoked_at')->map(fn ($r) => $r->role->value)->values()->all(),
            'workspaces' => $u->memberships->where('status', MembershipStatus::Active)->map(fn (WebshopMembership $m) => [
                'id' => $m->webshop->public_id, 'name' => $m->webshop->name, 'role' => $m->role->value,
            ])->values()->all(),
            'two_factor_enabled' => $u->hasEnabledTwoFactorAuthentication(),
            'registered_at' => $u->created_at?->toIso8601String(),
        ];
    }

    private function respond(User $user): JsonResponse
    {
        return response()->json(['data' => self::summary($user->load(['platformRoles', 'memberships.webshop:id,public_id,name']))]);
    }

    private function reason(Request $request): string
    {
        return (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }

    private function find(string $publicId): User
    {
        return (Str::isUlid($publicId) ? User::where('public_id', Str::lower($publicId))->first() : null) ?? throw new NotFoundHttpException;
    }
}
