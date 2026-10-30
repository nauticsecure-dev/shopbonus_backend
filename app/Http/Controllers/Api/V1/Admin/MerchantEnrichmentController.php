<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantEnrichmentReview;
use App\Models\MerchantEnrichmentRun;
use App\Models\User;
use App\Models\Webshop;
use App\Services\Enrichment\EnrichmentPipeline;
use App\Services\Enrichment\MerchantProfileAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantEnrichmentController extends Controller
{
    public function index(Request $request, MerchantProfileAi $ai): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['queued', 'running', 'awaiting_review', 'published', 'rejected', 'failed'])],
            'webshop' => ['sometimes', 'string', 'max:26'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = MerchantEnrichmentRun::query()->orderByDesc('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (isset($data['webshop'])) {
            $webshopId = Webshop::where('public_id', Str::lower($data['webshop']))->value('id');
            if ($webshopId === null) {
                return response()->json(['data' => [], 'availability' => $this->availability($ai), 'meta' => ['current_page' => 1, 'total' => 0, 'per_page' => 25, 'last_page' => 1]]);
            }
            $query->where('webshop_id', $webshopId);
        }

        $page = $query->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (MerchantEnrichmentRun $run) => $this->present($run))->all(),
            'availability' => $this->availability($ai),
            'meta' => ['current_page' => $page->currentPage(), 'total' => $page->total(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $run, MerchantProfileAi $ai): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($run)), 'availability' => $this->availability($ai)]);
    }

    public function approve(Request $request, string $run, EnrichmentPipeline $pipeline): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        $pipeline->approve($this->find($run), $request->user(), $data['note']);

        return response()->json(['data' => $this->present($this->find($run))]);
    }

    public function reject(Request $request, string $run, EnrichmentPipeline $pipeline): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);
        $pipeline->reject($this->find($run), $request->user(), $data['note']);

        return response()->json(['data' => $this->present($this->find($run))]);
    }

    public function retry(Request $request, string $run, EnrichmentPipeline $pipeline): JsonResponse
    {
        $newRun = $pipeline->retry($this->find($run), $request->user());

        return response()->json(['data' => $this->present($newRun)], 202);
    }

    /** @return array<string, mixed> */
    private function present(MerchantEnrichmentRun $run): array
    {
        $webshop = Webshop::findOrFail($run->webshop_id);

        return [
            'id' => $run->public_id,
            'webshop_id' => $webshop->public_id,
            'webshop_name' => $webshop->name,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'source_url' => $run->source_url,
            'research' => $run->research,
            'research_hash' => $run->research_hash,
            'text_model' => $run->text_model,
            'image_model' => $run->image_model,
            'proposal' => $run->proposal,
            'review_required' => $run->review_required,
            'risk_level' => $run->risk_level,
            'cover_ready' => $run->cover_path !== null,
            'cover_failure_code' => $run->cover_failure_code,
            'failure_code' => $run->failure_code,
            'created_at' => $run->created_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'published_at' => $run->published_at?->toIso8601String(),
            'human_review_required' => $run->review_required || ! config('enrichment.auto_publish'),
            'reviews' => MerchantEnrichmentReview::where('run_id', $run->id)->orderBy('id')->get()->map(fn (MerchantEnrichmentReview $r) => [
                'id' => $r->public_id,
                'reviewed_by' => User::findOrFail($r->reviewed_by)->public_id,
                'decision' => $r->decision,
                'note' => $r->note,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function availability(MerchantProfileAi $ai): array
    {
        return [
            'configured' => $ai->configured(),
            'provider' => 'openai',
            'auto_publish' => (bool) config('enrichment.auto_publish'),
            'effective_daily_run_limit' => (int) config('enrichment.daily_run_limit'),
        ];
    }

    private function find(string $id): MerchantEnrichmentRun
    {
        return MerchantEnrichmentRun::where('public_id', Str::lower($id))->first() ?? throw new NotFoundHttpException;
    }
}
