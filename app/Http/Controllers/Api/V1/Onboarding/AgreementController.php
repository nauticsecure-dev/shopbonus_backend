<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\AcceptAgreementRequest;
use App\Services\Agreements\AgreementService;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;

class AgreementController extends Controller
{
    public function __construct(private readonly AgreementService $agreements) {}

    /**
     * The current agreement, rendered for this webshop, plus acceptance state.
     */
    public function show(CurrentWorkspace $current): JsonResponse
    {
        $webshop = $current->webshop()->loadMissing('businessProfile');
        $template = $this->agreements->currentTemplate($webshop)
            ?? throw new ApiException('No merchant agreement is published yet.', 503, 'agreement_unavailable');
        $rendered = $this->agreements->render($template, $webshop);
        $latest = $this->agreements->latestAcceptance($webshop);

        return response()->json(['data' => [
            'title' => $template->title,
            'version' => $template->version,
            'locale' => $template->locale,
            'body' => $rendered,
            'document_hash' => $this->agreements->hash($rendered),
            'published_at' => $template->published_at?->toIso8601String(),
            'status' => $this->agreements->status($webshop),
            'latest_acceptance' => $latest === null ? null : [
                'id' => $latest->public_id,
                'version' => $latest->version,
                'document_hash' => $latest->document_hash,
                'signatory_name' => $latest->signatory_name,
                'accepted_at' => $latest->accepted_at->toIso8601String(),
            ],
        ]]);
    }

    public function accept(AcceptAgreementRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $current->webshop()->loadMissing('businessProfile');
        $acceptance = $this->agreements->accept($current->membership(), $request->safe()->only(['version', 'document_hash', 'signatory_name']));
        $webshop = $current->webshop()->fresh() ?? $current->webshop();

        return response()->json(['data' => [
            'id' => $acceptance->public_id,
            'version' => $acceptance->version,
            'document_hash' => $acceptance->document_hash,
            'signatory_name' => $acceptance->signatory_name,
            'accepted_at' => $acceptance->accepted_at->toIso8601String(),
            'webshop_status' => $webshop->status->value,
        ]], 201);
    }
}
