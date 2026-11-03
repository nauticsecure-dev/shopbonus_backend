<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\AuditEvent;
use App\Models\WebshopMembership;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Members are addressed by the user's public ID, looked up only inside the
 * resolved workspace.
 */
class MemberController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    /** Narrow merchant projection: never expose technical metadata, IPs or internal IDs. */
    public function history(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $events = AuditEvent::query()->where('webshop_id', $current->webshop()->id)
            ->where(function ($query) {
                $query->where('action', 'like', 'team.%')->orWhere('action', 'like', 'ownership.transfer_%');
            })->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => $events->getCollection()->map(fn (AuditEvent $event) => [
                'action' => $event->action,
                'occurred_at' => $event->occurred_at->toIso8601String(),
                'outcome' => $event->outcome->value,
            ]),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage()],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function index(CurrentWorkspace $current): AnonymousResourceCollection
    {
        $members = WebshopMembership::with('user')
            ->where('webshop_id', $current->webshop()->id)
            ->where('status', '!=', 'removed')
            ->orderByRaw("case role when 'owner' then 1 when 'manager' then 2 else 3 end")
            ->orderBy('id')
            ->get();

        return MemberResource::collection($members);
    }

    public function update(UpdateMemberRequest $request, CurrentWorkspace $current, string $member): JsonResponse
    {
        $role = $request->has('role') ? MembershipRole::from((string) $request->validated('role')) : null;
        $permissions = $request->has('permissions') ? array_values($request->validated('permissions')) : null;

        $updated = $this->team->updateMember($current->membership(), $this->find($current, $member), $role, $permissions);

        return MemberResource::make($updated)->response()->setStatusCode(200);
    }

    public function suspend(CurrentWorkspace $current, string $member): JsonResponse
    {
        return MemberResource::make($this->team->suspend($current->membership(), $this->find($current, $member)))
            ->response()->setStatusCode(200);
    }

    public function reactivate(CurrentWorkspace $current, string $member): JsonResponse
    {
        return MemberResource::make($this->team->reactivate($current->membership(), $this->find($current, $member)))
            ->response()->setStatusCode(200);
    }

    public function destroy(CurrentWorkspace $current, string $member): Response
    {
        $this->team->remove($current->membership(), $this->find($current, $member));

        return response()->noContent();
    }

    /**
     * Leave the workspace (any role; the final owner cannot leave).
     */
    public function leave(CurrentWorkspace $current): Response
    {
        $this->team->remove($current->membership(), $current->membership());

        return response()->noContent();
    }

    private function find(CurrentWorkspace $current, string $userPublicId): WebshopMembership
    {
        $membership = Str::isUlid($userPublicId)
            ? WebshopMembership::with('user')
                ->where('webshop_id', $current->webshop()->id)
                ->where('status', '!=', 'removed')
                ->whereHas('user', fn ($q) => $q->where('public_id', Str::lower($userPublicId)))
                ->first()
            : null;

        return ($membership ?? throw new NotFoundHttpException)->setRelation('webshop', $current->webshop());
    }
}
