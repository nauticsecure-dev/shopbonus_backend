<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for intentional, client-facing API errors raised by domain code
 * (e.g. an invalid state transition => 409). Its message IS shown to the
 * client, so never put secrets, PII or other tenants' data in it.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details  Extra machine-readable context safe for the client.
     * @param  array<string, string>  $headers  Extra response headers (e.g. Retry-After).
     */
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly string $errorCode = 'bad_request',
        public readonly array $details = [],
        public readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function conflict(string $message, string $errorCode = 'conflict', array $details = []): self
    {
        return new self($message, 409, $errorCode, $details);
    }
}
