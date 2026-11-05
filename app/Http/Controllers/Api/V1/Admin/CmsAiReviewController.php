<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CmsAiSuggestion;
use App\Services\Cms\CmsAiReviewService;
use App\Services\Cms\CmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CmsAiReviewController extends Controller
{
    public function store(Request $request, string $suggestion, CmsAiReviewService $reviews): JsonResponse
    {
        $d = $request->validate(['locale' => ['required', Rule::in(CmsService::LOCALES)], 'language_correct' => ['required', 'boolean'], 'meaning_preserved' => ['required', 'boolean'], 'note' => ['required', 'string', 'max:2000']]);
        $s = CmsAiSuggestion::where('public_id', $suggestion)->first() ?? throw new ApiException('Suggestion not found.', 404, 'not_found');
        $r = $reviews->review($s, $request->user(), $d['locale'], $d['language_correct'] && $d['meaning_preserved'], $d['note']);

        return response()->json(['data' => ['suggestion_id' => $s->public_id, 'accepted' => $r->accepted, 'locale' => $r->locale, 'proposal_hash' => $r->proposal_hash, 'scope' => 'Independent human language/meaning review; not proof of live-model quality or publication.']], 201);
    }
}
