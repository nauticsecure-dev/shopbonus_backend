<?php

namespace App\Http\Controllers\Api\V1\Workspaces;

use App\Http\Controllers\Controller;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\OwnershipTransfers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** D-108 ownership transfer (Trello #16): propose, accept, decline or cancel. */
class OwnershipTransferController extends Controller
{
    public function __construct(private readonly OwnershipTransfers $transfers) {}

    /** The pending transfer the current member is part of, or null. */
    public function show(CurrentWorkspace $current): JsonResponse
    {
        return response()->json(['data' => $this->transfers->visibleTo($current->membership())?->present()]);
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse
    {
        $data = $request->validate(['member' => ['required', 'string', 'size:26']]);

        return response()->json(['data' => $this->transfers->start($current->membership(), strtolower($data['member']))->present()], 201);
    }

    public function accept(CurrentWorkspace $current): JsonResponse
    {
        return response()->json(['data' => $this->transfers->accept($current->membership())->present()]);
    }

    public function destroy(CurrentWorkspace $current): JsonResponse
    {
        return response()->json(['data' => $this->transfers->withdraw($current->membership())->present()]);
    }
}
