<?php

namespace App\Services\Errors;

use App\Enums\ErrorSource;
use App\Events\ApplicationErrorRecorded;
use App\Logging\SensitiveDataProcessor;
use App\Models\ApplicationError;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores unexpected faults in application_errors (technical errors, separate
 * from the security audit trail). Never throws: if storage fails, the
 * original exception is still logged by the framework.
 */
class ErrorRecorder
{
    private const MAX_MESSAGE_LENGTH = 2000;

    private bool $recording = false;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly SensitiveDataProcessor $redactor,
    ) {}

    public function recordHttp(Throwable $e, ?Request $request = null, int $httpStatus = 500): ?ApplicationError
    {
        $route = $request?->route();

        return $this->store($e, ErrorSource::Http, [
            'http_status' => $httpStatus,
            'http_method' => $request?->getMethod(),
            'route' => $route?->uri(),
            'user_id' => $this->currentUserId(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function recordJobFailure(
        Throwable $e,
        string $jobName,
        ?string $queue,
        ?string $connection,
        array $context = [],
    ): ?ApplicationError {
        return $this->store($e, ErrorSource::Queue, [
            'job_name' => Str::limit($jobName, 255, ''),
            'queue' => $queue,
            'connection' => $connection,
        ], $context);
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $context
     */
    private function store(Throwable $e, ErrorSource $source, array $columns, array $context = []): ?ApplicationError
    {
        // Guards against recursion if storing the error itself throws and is reported.
        if ($this->recording) {
            return null;
        }

        $this->recording = true;

        try {
            $error = ApplicationError::create([
                'occurred_at' => now(),
                'request_id' => Context::get('request_id'),
                'source' => $source,
                'fingerprint' => sha1($e::class.'|'.$e->getFile().'|'.$e->getLine()),
                'exception_class' => $e::class,
                'message' => $this->safeMessage($e),
                'location' => Str::limit($this->relativePath($e->getFile()).':'.$e->getLine(), 512, ''),
                'webshop_id' => Context::get(AuditLogger::CONTEXT_WEBSHOP_ID),
                'context' => $context === [] ? null : $this->redactor->redactArray($context),
                ...$columns,
            ]);

            ApplicationErrorRecorded::dispatch($error);

            return $error;
        } catch (Throwable $storageFailure) {
            Log::error('Application error could not be stored', [
                'original_exception_class' => $e::class,
                'exception_class' => $storageFailure::class,
            ]);

            return null;
        } finally {
            $this->recording = false;
        }
    }

    /**
     * Query exceptions embed bound values (which may be PII or secrets) in
     * their message, so only the SQLSTATE and the SQL template are kept.
     */
    private function safeMessage(Throwable $e): string
    {
        $message = $e instanceof QueryException
            ? 'SQLSTATE['.$e->getCode().'] on query: '.$e->getSql()
            : $e->getMessage();

        return Str::limit($this->redactor->redactText($message), self::MAX_MESSAGE_LENGTH);
    }

    private function relativePath(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }

    private function currentUserId(): ?int
    {
        try {
            $id = $this->auth->guard()->id();
        } catch (Throwable) {
            return null;
        }

        return is_numeric($id) ? (int) $id : null;
    }
}
