<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Enums\WebshopStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WebshopDecisionRequest;
use App\Http\Resources\AdminWebshopResource;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Audit\AuditLogger;
use App\Services\Workspaces\WebshopLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Super Admin webshop review and status management (Trello #16, #3 §19).
 */
class WebshopController extends Controller
{
    public function __construct(
        private readonly WebshopLifecycle $lifecycle,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(WebshopStatus::class)],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($filters['search']) ? '%'.addcslashes($filters['search'], '%_\\').'%' : null;

        $webshops = Webshop::with(['businessProfile', 'memberships.user'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', $search)
                ->orWhereHas('memberships.user', fn ($u) => $u->where('email', 'like', $search))))
            ->orderByRaw("case status when 'pending_review' then 0 else 1 end")
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return AdminWebshopResource::collection($webshops);
    }

    public function show(string $webshop): JsonResponse
    {
        $model = $this->find($webshop)->load(['verifications', 'agreementAcceptances']);
        $this->audit->recordSafely('admin.webshop_viewed', category: AuditCategory::Admin, resource: $model, webshopId: $model->id);

        return AdminWebshopResource::make($model)->withDetail()->response()->setStatusCode(200);
    }

    public function evidence(string $webshop, string $file): Response
    {
        $model = $this->find($webshop)->load('verifications');
        $meta = $this->evidenceMeta($model, $file);
        $path = "verification-evidence/{$model->public_id}/{$file}.enc";

        if ($meta === null || ! Str::isUuid($file) || ! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException;
        }

        $this->audit->record('admin.evidence_downloaded', category: AuditCategory::Admin, resource: $model,
            webshopId: $model->id, metadata: ['file_id' => $file]);

        return response(base64_decode(Crypt::decryptString((string) Storage::disk('local')->get($path))), 200, [
            'Content-Type' => $meta['mime'],
            'Content-Disposition' => 'attachment; filename="'.$meta['name'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function approve(WebshopDecisionRequest $request, string $webshop): JsonResponse
    {
        return $this->respond($this->lifecycle->approve($this->admin($request), $this->find($webshop),
            $request->validated('reason'), $request->boolean('confirm_duplicate_claim')));
    }

    public function reject(WebshopDecisionRequest $request, string $webshop): JsonResponse
    {
        return $this->respond($this->lifecycle->reject($this->admin($request), $this->find($webshop), (string) $request->validated('reason')));
    }

    public function suspend(WebshopDecisionRequest $request, string $webshop): JsonResponse
    {
        return $this->respond($this->lifecycle->suspend($this->admin($request), $this->find($webshop), (string) $request->validated('reason')));
    }

    public function unsuspend(WebshopDecisionRequest $request, string $webshop): JsonResponse
    {
        return $this->respond($this->lifecycle->unsuspend($this->admin($request), $this->find($webshop), (string) $request->validated('reason')));
    }

    /**
     * @return array{id: string, name: string, mime: string, size: int}|null
     */
    private function evidenceMeta(Webshop $webshop, string $fileId): ?array
    {
        foreach ($webshop->verifications as $verification) {
            foreach ($verification->evidence['files'] ?? [] as $meta) {
                if (($meta['id'] ?? null) === $fileId) {
                    return $meta;
                }
            }
        }

        return null;
    }

    private function respond(Webshop $webshop): JsonResponse
    {
        return AdminWebshopResource::make($webshop->load(['businessProfile', 'memberships.user']))->response()->setStatusCode(200);
    }

    private function admin(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function find(string $publicId): Webshop
    {
        return (Str::isUlid($publicId) ? Webshop::with(['businessProfile', 'memberships.user'])->where('public_id', Str::lower($publicId))->first() : null)
            ?? throw new NotFoundHttpException;
    }
}
