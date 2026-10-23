<?php

use Illuminate\Support\Facades\Route;

// API-only backend: the web root just identifies the service. Clients use /api/v1.
Route::get('/', fn () => response()->json(['service' => 'shopbonus-api', 'api' => '/api/v1']));
