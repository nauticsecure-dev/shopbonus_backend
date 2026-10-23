<?php

namespace App\Http\Controllers\Api\V1\Offers;

use App\Enums\OfferAcquisition;
use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Offers\SaveOfferRequest;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Models\OfferVersion;
use App\Services\Offers\OfferService;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers) {}

    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection
    {
        $filters = $request->validate(['status' => ['sometimes', Rule::enum(OfferStatus::class)], 'search' => ['sometimes', 'string', 'max:100']]);

        $offers = Offer::with('currentVersion')
            ->where('webshop_id', $current->webshop()->id)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereHas('currentVersion',
                fn ($v) => $v->where('name', 'like', '%'.addcslashes($s, '%_\\').'%')))
            ->latest('id')->get();

        return OfferResource::collection($offers);
    }

    public function store(SaveOfferRequest $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validated();
        $offer = $this->offers->create($current->membership(), OfferAcquisition::from($data['acquisition']), $data);

        return OfferResource::make($offer)->response()->setStatusCode(201);
    }

    public function show(CurrentWorkspace $current, string $offer): JsonResponse
    {
        $model = $this->find($current, $offer);
        $versions = OfferVersion::where('offer_id', $model->id)->orderBy('version')->get(['version', 'locked_at', 'created_at']);

        return OfferResource::make($model)->additional(['versions' => $versions->map(fn ($v) => [
            'version' => $v->version, 'locked' => $v->locked_at !== null, 'created_at' => $v->created_at?->toIso8601String(),
        ])])->response()->setStatusCode(200);
    }

    public function update(SaveOfferRequest $request, CurrentWorkspace $current, string $offer): JsonResponse
    {
        return OfferResource::make($this->offers->update($current->membership(), $this->find($current, $offer), $request->validated()))
            ->response()->setStatusCode(200);
    }

    public function activate(CurrentWorkspace $current, string $offer): JsonResponse
    {
        return $this->transition($current, $offer, OfferStatus::Active);
    }

    public function pause(CurrentWorkspace $current, string $offer): JsonResponse
    {
        return $this->transition($current, $offer, OfferStatus::Paused);
    }

    public function end(CurrentWorkspace $current, string $offer): JsonResponse
    {
        return $this->transition($current, $offer, OfferStatus::Ended);
    }

    private function transition(CurrentWorkspace $current, string $offer, OfferStatus $to): JsonResponse
    {
        return OfferResource::make($this->offers->transition($this->find($current, $offer), $to))->response()->setStatusCode(200);
    }

    private function find(CurrentWorkspace $current, string $publicId): Offer
    {
        return (Str::isUlid($publicId)
            ? Offer::with('currentVersion')->where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null) ?? throw new NotFoundHttpException;
    }
}
