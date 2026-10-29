<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Api\V1\Consumer\WalletController as ConsumerWalletController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin normalised alias over the consumer wallet ledger summary.
 * Reuses Consumer\WalletController::summary — no duplicate financial logic.
 */
class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $summary = ConsumerWalletController::summary($request->user());
        $eur = $summary['balances']['eur_credit'] ?? [];

        return response()->json(['data' => [
            'available_minor' => (int) ($eur['available'] ?? 0),
            'total_earned_minor' => (int) ($eur['total_earned'] ?? 0),
            'total_spent_minor' => (int) ($eur['total_spent'] ?? 0),
            'currency' => 'EUR',
            'unit' => 'EUR_REDEMPTION_CREDIT',
            'updated_at' => now()->toIso8601String(),
            'withdrawable' => false,
            'notice' => $summary['notice'] ?? null,
            // Authoritative eur_credit projection also exposed for clients that already use it.
            'balances' => $summary['balances'],
        ]]);
    }
}
