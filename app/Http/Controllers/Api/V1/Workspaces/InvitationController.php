<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\InvitationStatus;
use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\InvitationTokenRequest;
use App\Http\Requests\Workspaces\InviteMemberRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\WebshopInvitation;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\TeamService;
use App\Support\Mask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvitationController extends Controller
{
    public function __construct(private readonly TeamService $team) {}

    public function index(CurrentWorkspace $current): AnonymousResourceCollection
    {
        $invitations = WebshopInvitation::where('webshop_id', $current->webshop()->id)
            ->where('status', InvitationStatus::Pending)
            ->latest('id')
            ->get();

        return InvitationResource::collection($invitations);
    }

    public function store(InviteMemberRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $invitation = $this->team->invite(
            $current->membership(),
            (string) $request->validated('email'),
            MembershipRole::from((string) $request->validated('role')),
            array_values($request->validated('permissions', [])),
        );

        return InvitationResource::make($invitation)->response()->setStatusCode(201);
    }

    public function resend(CurrentWorkspace $current, string $invitation): JsonResponse
    {
        $model = $this->find($current, $invitation)->setRelation('webshop', $current->webshop());

        return InvitationResource::make($this->team->resend($current->membership(), $model))->response()->setStatusCode(200);
    }

    public function destroy(CurrentWorkspace $current, string $invitation): JsonResponse
    {
        return InvitationResource::make($this->team->cancel($this->find($current, $invitation)))->response()->setStatusCode(200);
    }

    /**
     * Public: shows who invited whom to which webshop, for the token holder.
     */
    public function preview(InvitationTokenRequest $request): JsonResponse
    {
        $invitation = $this->team->findOpen((string) $request->validated('token'));

        return response()->json(['data' => [
            'webshop' => ['name' => $invitation->webshop->name],
            'role' => $invitation->role->value,
            'invited_by' => $invitation->inviter->first_name,
            'email_hint' => Mask::email($invitation->email),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function accept(InvitationTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->team->accept($user, (string) $request->validated('token'));
        $webshop = $membership->webshop->setRelation('memberships', collect([$membership]));

        return WorkspaceResource::make($webshop)->response()->setStatusCode(200);
    }

    private function find(CurrentWorkspace $current, string $publicId): WebshopInvitation
    {
        $invitation = Str::isUlid($publicId)
            ? WebshopInvitation::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null;

        return $invitation ?? throw new NotFoundHttpException;
    }
}
