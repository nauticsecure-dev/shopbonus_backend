<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Every route lives under a version prefix (/api/v1). A future /api/v2 gets
| its own group; never change a released v1 contract in place.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:api')->group(function () {
    Route::get('health', [HealthController::class, 'live'])->name('health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
});
