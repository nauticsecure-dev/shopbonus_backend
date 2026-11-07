<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\QaExecutionRequest;
use App\Services\Qa\QaExecutions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QaExecutionsController extends Controller
{
    public function index(Request $request, QaExecutions $service): JsonResponse
    {
        $service->authorize($request->user());
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $rows = QaExecutionRequest::orderByDesc('id')->paginate(20, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => $rows->getCollection()->map(fn ($row) => $service->present($row))->all(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]], headers: ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, QaExecutions $service): JsonResponse
    {
        $data = $request->validate(['deployment_sha' => ['required', 'regex:/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D'],
            'trigger' => ['required', 'in:nightly,weekly,after_deploy'], 'request_key' => ['required', 'uuid']]);
        $row = $service->request($request->user(), $data['deployment_sha'], $data['trigger'], $data['request_key']);

        return response()->json(['data' => $service->present($row)], $row->wasRecentlyCreated ? 202 : 200, ['Cache-Control' => 'private, no-store']);
    }
}
