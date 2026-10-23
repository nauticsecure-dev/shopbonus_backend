<?php

namespace App\Http\Responses;

use App\Http\Resources\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;

/**
 * Fortify login (200) and registration (201) both answer with the /me
 * payload, so the SPA routes on the server-computed destination.
 */
class MeResponse implements LoginResponse, RegisterResponse
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        $status = $request->routeIs('register.store') ? 201 : 200;

        return (new MeResource($request->user()))->response()->setStatusCode($status);
    }
}
