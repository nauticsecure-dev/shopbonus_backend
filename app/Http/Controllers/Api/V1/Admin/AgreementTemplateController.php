<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AgreementTemplateStatus;
use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveAgreementTemplateRequest;
use App\Http\Resources\AgreementTemplateResource;
use App\Models\AgreementTemplate;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Super Admin management of versioned agreement templates. Drafts are
 * editable; publishing freezes the text and retires the previous version of
 * the same key and locale.
 */
class AgreementTemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AgreementTemplateResource::collection(
            AgreementTemplate::query()
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->orderBy('key')->orderBy('locale')->latest('id')->get(),
        );
    }

    public function store(SaveAgreementTemplateRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $data = $request->validated();

        if (AgreementTemplate::where('key', $data['key'])->where('locale', $data['locale'])->where('version', $data['version'])->exists()) {
            throw ApiException::conflict('This version already exists for this agreement and language.', 'template_version_exists');
        }

        $template = AgreementTemplate::create($data + ['status' => AgreementTemplateStatus::Draft, 'created_by' => $admin->id]);
        $this->audit->record('agreement.template_created', category: AuditCategory::Admin, resource: $template,
            metadata: ['key' => $template->key, 'version' => $template->version, 'locale' => $template->locale]);

        return AgreementTemplateResource::make($template)->response()->setStatusCode(201);
    }

    public function update(SaveAgreementTemplateRequest $request, string $template): JsonResponse
    {
        $model = $this->find($template);

        if ($model->status !== AgreementTemplateStatus::Draft) {
            throw ApiException::conflict('Published templates cannot be edited. Create a new version.', 'template_not_draft');
        }

        $model->update($request->validated());
        $this->audit->record('agreement.template_updated', category: AuditCategory::Admin, resource: $model);

        return AgreementTemplateResource::make($model)->response()->setStatusCode(200);
    }

    public function publish(Request $request, string $template): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $model = $this->find($template);

        if ($model->status !== AgreementTemplateStatus::Draft) {
            throw ApiException::conflict('Only draft templates can be published.', 'template_not_draft');
        }

        DB::transaction(function () use ($model, $admin) {
            AgreementTemplate::where('key', $model->key)->where('locale', $model->locale)
                ->where('status', AgreementTemplateStatus::Published)
                ->update(['status' => AgreementTemplateStatus::Retired, 'retired_at' => now()]);

            $model->forceFill(['status' => AgreementTemplateStatus::Published, 'published_at' => now(), 'published_by' => $admin->id])->save();

            $this->audit->record('agreement.template_published', category: AuditCategory::Admin, resource: $model,
                metadata: ['key' => $model->key, 'version' => $model->version, 'locale' => $model->locale,
                    'requires_reacceptance' => $model->requires_reacceptance]);
        });

        return AgreementTemplateResource::make($model)->response()->setStatusCode(200);
    }

    private function find(string $publicId): AgreementTemplate
    {
        return (Str::isUlid($publicId) ? AgreementTemplate::where('public_id', Str::lower($publicId))->first() : null)
            ?? throw new NotFoundHttpException;
    }
}
