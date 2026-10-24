<?php

namespace App\Http\Controllers\Api\V1\Affiliate;

use App\Affiliate\AffiliateNetworkRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateConnection;
use App\Services\Affiliate\AffiliateProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Network postback (conversion ping). Authenticated only by the secret
 * token in the URL, so it is untrusted: it can record a conversion as
 * "tracked" but never approves, credits or changes a conversion the network
 * API already reported (Trello #19 "block forged conversion callbacks").
 */
class PostbackController extends Controller
{
    public function receive(Request $request, string $token, AffiliateNetworkRegistry $registry, AffiliateProcessor $processor): JsonResponse
    {
        $connection = AffiliateConnection::where('postback_token_hash', AffiliateConnection::hashToken($token))->where('status', '!=', 'disabled')->first()
            ?? throw new ApiException('Unknown postback endpoint.', 404, 'not_found');

        try {
            $data = $registry->for($connection->network)->normalizeTransaction($request->all());
        } catch (Throwable) {
            throw new ApiException('The postback could not be read.', 422, 'postback_invalid');
        }

        $tx = $processor->ingest($connection, $data, 'postback');

        return response()->json(['data' => ['received' => true, 'status' => $tx->status]], 202);
    }
}
