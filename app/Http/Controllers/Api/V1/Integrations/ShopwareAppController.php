<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\ShopwareAppRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Shopware app-system `<setup>` endpoints, called by the merchant's Shopware shop, never by clients. */
class ShopwareAppController extends Controller
{
    public function __construct(private readonly ShopwareAppRegistration $registration) {}

    public function register(Request $request): JsonResponse
    {
        // Shopware expects the bare handshake object, not the API `data` envelope.
        return response()->json($this->registration->register($request));
    }

    public function confirm(Request $request): Response
    {
        $this->registration->confirm($request);

        return response()->noContent();
    }
}
