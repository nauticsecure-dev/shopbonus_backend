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
        $probeId = $this->connectivity->startBrowserProbe($request->user());

        return response()->json(['data' => ['probe_id' => $probeId, 'channel' => 'private-shopbonus.user.'.$request->user()->public_id.'.notifications',
            'event' => 'support.connectivity.browser_probe', 'expires_in' => 60]], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function acknowledge(Request $request, string $nonce): JsonResponse
    {
        $data = $request->validate(['nonce' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/D']]);
        $this->connectivity->acknowledgeBrowserProbe($request->user(), strtolower($nonce), $data['nonce']);

        return response()->json(['data' => $this->connectivity->evidence($request->user())], 200, ['Cache-Control' => 'private, no-store']);
    }
}
