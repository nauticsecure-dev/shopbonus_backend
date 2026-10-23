<?php

namespace App\Exceptions;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error in one stable envelope:
 *
 *   { "error": { "status": 404, "code": "not_found", "message": "...", "request_id": "..." } }
 *
 * Validation errors add "fields"; ApiException may add "details". Messages
 * for framework exceptions are fixed per status so internals (model class
 * names, SQL, paths) never leak, and a 404 looks identical whether a record
 * does not exist or belongs to another workspace.
 */
class ApiExceptionRenderer
{
    /** @var array<int, array{0: string, 1: string}> */
    private const STATUS_DEFAULTS = [
        400 => ['bad_request', 'The request could not be processed.'],
        401 => ['unauthenticated', 'Authentication is required.'],
        403 => ['forbidden', 'You are not allowed to perform this action.'],
        404 => ['not_found', 'The requested resource was not found.'],
        405 => ['method_not_allowed', 'This method is not allowed for the requested resource.'],
        409 => ['conflict', 'The request conflicts with the current state of the resource.'],
        415 => ['unsupported_media_type', 'The request content type is not supported.'],
        419 => ['csrf_token_mismatch', 'The session or CSRF token has expired. Refresh and try again.'],
        422 => ['validation_failed', 'The given data was invalid.'],
        429 => ['too_many_requests', 'Too many requests. Please slow down.'],
        500 => ['server_error', 'An unexpected error occurred.'],
        503 => ['service_unavailable', 'The service is temporarily unavailable.'],
    ];

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::appliesTo($request)) {
            return null;
        }

        return $this->render($e, $request);
    }

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function render(Throwable $e, Request $request): JsonResponse
    {
        $status = $this->statusFor($e);
        [$code, $message] = self::STATUS_DEFAULTS[$status]
            ?? ($status >= 500 ? self::STATUS_DEFAULTS[500] : self::STATUS_DEFAULTS[400]);
        $headers = match (true) {
            $e instanceof HttpExceptionInterface => $e->getHeaders(),
            $e instanceof ApiException => $e->headers,
            default => [],
        };

        $error = [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'request_id' => AssignRequestId::resolve($request),
        ];

        if ($e instanceof ApiException) {
            $error['code'] = $e->errorCode;
            $error['message'] = $e->getMessage();

            if ($e->details !== []) {
                $error['details'] = $e->details;
            }
        }

        // Laravel maps a failed origin check (Sec-Fetch-Site) to a 403. Retrying
        // with a fresh CSRF token cannot fix it, so it is not a 419.
        if ($e instanceof OriginMismatchException || $e->getPrevious() instanceof OriginMismatchException) {
            $error['code'] = 'origin_not_allowed';
            $error['message'] = 'Requests from this origin are not allowed.';
        }

                if ($e instanceof ValidationException) {
            $error['fields'] = $e->errors();
        }

        // Intentional ApiExceptions are not faults: never attach internals to them.
        if ($status >= 500 && config('app.debug') && ! $e instanceof ApiException) {
            $error['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ];
        }

        $headers[AssignRequestId::HEADER] = $error['request_id'];

        return new JsonResponse(['error' => $error], $status, $headers);
    }

    private function statusFor(Throwable $e): int
    {
        return match (true) {
            $e instanceof ApiException => $e->status,
            $e instanceof OriginMismatchException => 403,
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => $e->hasStatus() ? (int) $e->status() : 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof TokenMismatchException => 419,
            $e instanceof ThrottleRequestsException => 429,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }
}
