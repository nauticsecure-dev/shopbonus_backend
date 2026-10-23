<?php

use App\Http\Controllers\Docs\ApiDocsController;
use Illuminate\Support\Facades\Route;

// API-only backend: the web root just identifies the service. Clients use /api/v1.
Route::get('/', fn () => response()->json(array_filter([
    'service' => 'shopbonus-api',
    'api' => '/api/v1',
    'docs' => config('shopbonus.api.docs_enabled') ? url('/docs') : null,
])));

// Browsable API docs rendered from docs/api (API_DOCS_ENABLED; off in production by default).
if (config('shopbonus.api.docs_enabled')) {
    Route::prefix('docs')->name('docs.')->group(function () {
        Route::get('/', [ApiDocsController::class, 'index'])->name('index');
        Route::get('openapi.yaml', [ApiDocsController::class, 'spec'])->name('spec');
        Route::get('reference', [ApiDocsController::class, 'reference'])->name('reference');
    });
}
