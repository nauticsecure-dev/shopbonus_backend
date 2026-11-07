<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportConnectivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Browser-delivery probe for support realtime: the admin's browser echoes a nonce it received over Reverb. */
class SupportConnectivityController extends Controller
{
    public function __construct(private readonly SupportConnectivity $connectivity) {}

    public function start(Request $request): JsonResponse
    {
        $nonce = $this->connectivity->startBrowserProbe($request->user());

        return response()->json(['data' => ['nonce' => $nonce, 'channel' => 'private-shopbonus.user.'.$request->user()->public_id.'.notifications',
            'event' => 'support.connectivity.browser_probe', 'expires_in' => 60]], 201);
    }

    public function acknowledge(Request $request, string $nonce): JsonResponse
    {
        $this->connectivity->acknowledgeBrowserProbe($request->user(), strtolower($nonce));

        return response()->json(['data' => $this->connectivity->evidence()]);
    }
}
