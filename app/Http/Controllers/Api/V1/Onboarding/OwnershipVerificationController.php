<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Enums\VerificationMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use App\Http\Requests\Onboarding\ManualReviewRequest;
use App\Http\Requests\Onboarding\StartVerificationRequest;
use App\Http\Resources\VerificationResource;
use App\Models\WebshopVerification;
use App\Services\Onboarding\OwnershipVerification;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OwnershipVerificationController extends Controller
{
    public function __construct(private readonly OwnershipVerification $ownership) {}

    public function index(CurrentWorkspace $current): AnonymousResourceCollection
    {
        return VerificationResource::collection($current->webshop()->verifications()->latest('id')->limit(20)->get());
    }

    public function store(StartVerificationRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $this->prepare($current);
        $verification = $this->ownership->start(
            $current->membership(),
            VerificationMethod::from((string) $request->validated('method')),
            $request->validated('mailbox'),
        );

        return VerificationResource::make($verification)->response()->setStatusCode(201);
    }

    public function check(CurrentWorkspace $current, string $verification): JsonResponse
    {
        $this->prepare($current);

        return VerificationResource::make($this->ownership->check($current->membership(), $this->find($current, $verification)))
            ->response()->setStatusCode(200);
    }

    public function confirmCode(VerifyEmailCodeRequest $request, CurrentWorkspace $current, string $verification): JsonResponse
    {
        $this->prepare($current);
        $result = $this->ownership->confirmEmailCode($current->membership(), $this->find($current, $verification), (string) $request->validated('code'));

        return VerificationResource::make($result)->response()->setStatusCode(200);
    }

    public function review(ManualReviewRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $this->prepare($current);
        $files = array_values($request->file('evidence', []));
        $verification = $this->ownership->submitManualReview($current->membership(), (string) $request->validated('notes'), $files);

        return VerificationResource::make($verification)->response()->setStatusCode(201);
    }

    private function prepare(CurrentWorkspace $current): void
    {
        $current->webshop()->loadMissing('businessProfile');
    }

    private function find(CurrentWorkspace $current, string $publicId): WebshopVerification
    {
        $verification = Str::isUlid($publicId)
            ? $current->webshop()->verifications()->where('public_id', Str::lower($publicId))->first()
            : null;

        return ($verification ?? throw new NotFoundHttpException)->setRelation('webshop', $current->webshop());
    }
}
