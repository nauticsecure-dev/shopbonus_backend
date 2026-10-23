<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Enums\EcommercePlatform;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Integrations\ConnectionManager;
use App\Integrations\ConnectorRegistry;
use App\Models\WebshopConnection;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConnectionController extends Controller
{
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly ConnectorRegistry $registry,
    ) {}

    /** Platform catalogue with honest availability and verification state. */
    public function platforms(): JsonResponse
    {
        return response()->json(['data' => $this->registry->catalogue()]);
    }

    public function index(CurrentWorkspace $current): AnonymousResourceCollection
    {
        return ConnectionResource::collection(
            WebshopConnection::where('webshop_id', $current->webshop()->id)->latest('id')->get(),
        );
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $platform = EcommercePlatform::from((string) $request->validate([
            'platform' => ['required', Rule::enum(EcommercePlatform::class)],
        ])['platform']);

        [$connection, $secrets] = $this->connections->create($current->membership(), $platform, $request->except('platform'));

        return ConnectionResource::make($connection)->withSetup()->withSecrets($secrets)->response()->setStatusCode(201);
    }

    public function show(CurrentWorkspace $current, string $connection): JsonResponse
    {
        return ConnectionResource::make($this->find($current, $connection))->withSetup()->response()->setStatusCode(200);
    }

    public function replaceCredentials(Request $request, CurrentWorkspace $current, string $connection): JsonResponse
    {
        $model = $this->find($current, $connection);
        $secrets = $this->connections->replaceCredentials($model, $request->all());

        return ConnectionResource::make($model)->withSetup()->withSecrets($secrets)->response()->setStatusCode(200);
    }

    public function verify(CurrentWorkspace $current, string $connection): JsonResponse
    {
        return response()->json(['data' => $this->connections->checklist($this->find($current, $connection))]);
    }

    public function destroy(CurrentWorkspace $current, string $connection): JsonResponse
    {
        return ConnectionResource::make($this->connections->disconnect($this->find($current, $connection)))->response()->setStatusCode(200);
    }

    /** Sanitised event history: no payloads, no PII. */
    public function events(CurrentWorkspace $current, string $connection): JsonResponse
    {
        $events = $this->find($current, $connection)->events()->latest('id')->limit(50)->get()->map(fn ($e) => [
            'id' => $e->public_id,
            'topic' => $e->topic,
            'status' => $e->status->value,
            'delivery_count' => $e->delivery_count,
            'result' => $e->result,
            'error_code' => $e->error_code,
            'received_at' => $e->received_at->toIso8601String(),
            'processed_at' => $e->processed_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $events]);
    }

    private function find(CurrentWorkspace $current, string $publicId): WebshopConnection
    {
        $connection = Str::isUlid($publicId)
            ? WebshopConnection::where('webshop_id', $current->webshop()->id)->where('public_id', Str::lower($publicId))->first()
            : null;

        return ($connection ?? throw new NotFoundHttpException)->setRelation('webshop', $current->webshop());
    }
}
