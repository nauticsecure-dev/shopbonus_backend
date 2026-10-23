<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\CreateWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspaceService $workspaces) {}

    /**
     * Workspaces the user is an active member of (the switcher list).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));

        $webshops = Webshop::query()
            ->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id)->where('status', MembershipStatus::Active))
            ->with(['memberships' => fn ($q) => $q->where('user_id', $user->id)])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->get();

        return WorkspaceResource::collection($webshops);
    }

    public function store(CreateWorkspaceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $webshop = $this->workspaces->createDraft($user, $request->validated());

        return WorkspaceResource::make($webshop)->response()->setStatusCode(201);
    }

    public function show(CurrentWorkspace $current): JsonResponse
    {
        return WorkspaceResource::make($current->webshop())->response()->setStatusCode(200);
    }

    public function update(UpdateWorkspaceRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $webshop = $this->workspaces->rename($current->webshop(), (string) $request->validated('name'));

        return WorkspaceResource::make($webshop)->response()->setStatusCode(200);
    }

    public function select(Request $request, CurrentWorkspace $current): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->workspaces->select($user, $current->webshop());

        return WorkspaceResource::make($current->webshop())->response()->setStatusCode(200);
    }
}
