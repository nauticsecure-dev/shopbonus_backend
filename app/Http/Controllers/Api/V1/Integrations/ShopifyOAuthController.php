<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConnectionResource;
use App\Integrations\ShopifyOAuth;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopifyOAuthController extends Controller
{
    public function start(Request $request, CurrentWorkspace $current, ShopifyOAuth $oauth): JsonResponse
    {
        $data = $request->validate(['shop_domain' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9][a-z0-9-]*\.myshopify\.com\z/D']]);

        return response()->json(['data' => $oauth->start($request, $current->membership(), $data['shop_domain'])], 200, ['Cache-Control' => 'no-store']);
    }

    public function callback(Request $request, ShopifyOAuth $oauth): JsonResponse
    {
        if ($request->bearerToken() !== null) {
            throw new ApiException('Send either the session cookie or a bearer token, not both.', 400, 'ambiguous_credentials');
        }

        return ConnectionResource::make($oauth->complete($request))->response()->header('Cache-Control', 'no-store');
    }
}
