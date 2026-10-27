<?php

namespace App\Http\Controllers\Api\V1\Consumer;

use App\Http\Controllers\Controller;
use App\Services\Vouchers\PromotionalClaims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionalClaimController extends Controller
{
    public function store(Request $request, string $slug, PromotionalClaims $claims): JsonResponse
    {
        $voucher = $claims->claim($slug, $request->user());

        return response()->json(['data' => ['id' => $voucher->public_id, 'status' => $voucher->status->value,
            'voucher_url' => route('api.v1.consumer.vouchers.show', ['id' => $voucher->public_id])]], 201);
    }
}
