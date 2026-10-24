<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Mollie webhook. Mollie posts only `id`; the body is never trusted: the
 * service fetches the payment from Mollie and acts on that. Always 200 for
 * a well-formed ID (unknown IDs included), so nothing is revealed.
 */
class MollieWebhookController extends Controller
{
    public function receive(Request $request, CheckoutService $checkouts): JsonResponse
    {
        $id = (string) $request->input('id', '');
        if (preg_match('/^tr_[A-Za-z0-9]{4,40}$/', $id) !== 1) {
            return response()->json(['error' => ['status' => 422, 'code' => 'invalid_payment_id', 'message' => 'Invalid payment ID.', 'request_id' => Context::get('request_id')]], 422);
        }

        $checkouts->handleWebhook($id);

        return response()->json(['data' => ['received' => true]]);
    }
}
