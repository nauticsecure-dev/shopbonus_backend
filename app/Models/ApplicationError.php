<?php

namespace App\Models;

use App\Enums\ErrorSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Technical error occurrence. Write through App\Services\Errors\ErrorRecorder.
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property string|null $request_id
 * @property ErrorSource $source
 * @property string $fingerprint
 * @property string $exception_class
 * @property string $message
 * @property string|null $location
 * @property int|null $http_status
 * @property string|null $http_method
 * @property string|null $route
 * @property int|null $user_id
 * @property int|null $webshop_id
 * @property string|null $job_name
 * @property string|null $queue
 * @property string|null $connection
 * @property array<string, mixed>|null $context
 */
class ApplicationError extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'source' => ErrorSource::class,
            'http_status' => 'integer',
            'context' => 'array',
        ];
    }
}
