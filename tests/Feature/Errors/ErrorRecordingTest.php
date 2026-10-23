<?php

namespace Tests\Feature\Errors;

use App\Enums\ErrorSource;
use App\Events\ApplicationErrorRecorded;
use App\Exceptions\ApiException;
use App\Models\ApplicationError;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ErrorRecordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_test')->group(function () {
            Route::get('boom/{thing}', fn () => throw new RuntimeException('Failed for jan@example.nl'));
            Route::get('bad-sql', fn () => DB::select('select * from no_such_table where email = ?', ['secret@example.nl']));
            Route::get('conflict', fn () => throw ApiException::conflict('Already reserved.'));
            Route::post('validate', fn (Request $r) => $r->validate(['x' => 'required']));
        });
    }

    public function test_unexpected_http_exception_is_recorded_redacted_and_correlated(): void
    {
        Event::fake([ApplicationErrorRecorded::class]);

        $response = $this->getJson('/api/v1/_test/boom/5', ['X-Request-Id' => 'req-err-500-01']);

        $response->assertStatus(500);
        $error = ApplicationError::sole();
        $this->assertSame(ErrorSource::Http, $error->source);
        $this->assertSame('req-err-500-01', $error->request_id);
        $this->assertSame(RuntimeException::class, $error->exception_class);
        $this->assertSame('Failed for [REDACTED_EMAIL]', $error->message);
        $this->assertSame(500, $error->http_status);
        $this->assertSame('GET', $error->http_method);
        $this->assertSame('api/v1/_test/boom/{thing}', $error->route);
        $this->assertStringStartsWith('tests/Feature/Errors/ErrorRecordingTest.php:', $error->location);
        $this->assertSame(40, strlen($error->fingerprint));
        Event::assertDispatched(ApplicationErrorRecorded::class, fn ($e) => $e->error->is($error));
    }

    public function test_records_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/_test/boom/1')->assertStatus(500);

        $this->assertSame($user->id, ApplicationError::sole()->user_id);
    }

    public function test_query_exception_never_stores_bound_values(): void
    {
        $this->getJson('/api/v1/_test/bad-sql')->assertStatus(500);

        $error = ApplicationError::sole();
        $this->assertStringContainsString('select * from no_such_table where email = ?', $error->message);
        $this->assertStringNotContainsString('secret@example.nl', $error->message);
    }

    public function test_same_fault_gets_same_fingerprint(): void
    {
        $this->getJson('/api/v1/_test/boom/1');
        $this->getJson('/api/v1/_test/boom/2');

        $this->assertSame(1, ApplicationError::distinct()->count('fingerprint'));
        $this->assertSame(2, ApplicationError::count());
    }

    public function test_intentional_api_exceptions_and_4xx_are_not_recorded(): void
    {
        $this->getJson('/api/v1/_test/conflict')->assertStatus(409);
        $this->postJson('/api/v1/_test/validate')->assertStatus(422);
        $this->getJson('/api/v1/nope')->assertNotFound();

        $this->assertSame(0, ApplicationError::count());
    }

    public function test_storage_failure_still_returns_the_500_envelope(): void
    {
        Schema::drop('application_errors');

        $this->getJson('/api/v1/_test/boom/1')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'server_error');
    }

    public function test_failed_job_is_recorded_once_with_originating_request_id(): void
    {
        Context::add('request_id', 'req-dispatching-job');

        try {
            FailingTestJob::dispatch();
        } catch (RuntimeException) {
            // The sync queue rethrows after marking the job failed.
        }

        $error = ApplicationError::sole();
        $this->assertSame(ErrorSource::Queue, $error->source);
        $this->assertSame(FailingTestJob::class, $error->job_name);
        $this->assertSame('sync', $error->connection);
        $this->assertSame('req-dispatching-job', $error->request_id);
        $this->assertSame('Provider timeout for [REDACTED_EMAIL]', $error->message);
        $this->assertArrayHasKey('attempts', $error->context);
    }
}

class FailingTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Provider timeout for piet@shop.nl');
    }
}
