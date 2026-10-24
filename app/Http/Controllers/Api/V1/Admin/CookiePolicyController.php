<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditCategory;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Content\CookieConsentController;
use App\Http\Controllers\Controller;
use App\Models\CookieConsent;
use App\Models\CookiePolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Consent\CookieConsents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin cookie policy management (Trello #5): draft, publish (step-up),
 * consent statistics. Trackers are an allowlist; no pasted code.
 */
class CookiePolicyController extends Controller
{
    public function __construct(private readonly CookieConsents $consents, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => CookiePolicy::orderByDesc('id')->get()->map(fn (CookiePolicy $p) => ['id' => $p->public_id, 'version' => $p->version, 'status' => $p->status,
            'requires_reconsent' => $p->requires_reconsent, 'published_at' => $p->published_at?->toIso8601String()])->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->consents->validatePolicy($request->all());
        if (CookiePolicy::where('version', $data['version'])->exists()) {
            throw ValidationException::withMessages(['version' => 'This version already exists.']);
        }
        $p = CookiePolicy::create(['version' => $data['version'], 'status' => 'draft', 'categories' => $data['categories'], 'texts' => $data['texts'],
            'requires_reconsent' => (bool) ($data['requires_reconsent'] ?? false), 'created_by' => $request->user()->id]);
        $this->audit->record('cookie_policy.created', category: AuditCategory::Admin, resource: $p, actorUserId: $request->user()->id, metadata: ['version' => $p->version]);

        return response()->json(['data' => ['id' => $p->public_id, 'status' => $p->status] + CookieConsentController::present($p, 'nl')], 201);
    }

    public function update(Request $request, string $policy): JsonResponse
    {
        $p = $this->find($policy);
        if ($p->status !== 'draft') {
            throw ApiException::conflict('Published policies cannot change; create a new version.', 'policy_not_draft');
        }
        $data = $this->consents->validatePolicy(['version' => $p->version] + $request->all());
        $p->forceFill(['categories' => $data['categories'], 'texts' => $data['texts'], 'requires_reconsent' => (bool) ($data['requires_reconsent'] ?? false)])->save();

        return response()->json(['data' => ['id' => $p->public_id, 'status' => $p->status] + CookieConsentController::present($p, 'nl')]);
    }

    public function publish(Request $request, string $policy): JsonResponse
    {
        $p = $this->consents->publish($this->find($policy), $request->user()->id);

        return response()->json(['data' => ['id' => $p->public_id, 'status' => $p->status] + CookieConsentController::present($p, 'nl')]);
    }

    public function stats(Request $request): JsonResponse
    {
        $data = $request->validate(['version' => ['sometimes', 'string', 'max:32', Rule::exists('cookie_policies', 'version')]]);
        $version = $data['version'] ?? CookiePolicy::current()?->version;

        return response()->json(['data' => ['version' => $version, 'by_action' => CookieConsent::where('policy_version', $version)->groupBy('action')->selectRaw('action, COUNT(*) n')->pluck('n', 'action')
            ->map(fn ($n) => (int) $n)->all()]]);
    }

    private function find(string $id): CookiePolicy
    {
        return CookiePolicy::where('public_id', $id)->first() ?? throw new ApiException('Policy not found.', 404, 'not_found');
    }
}
