<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\TraceProbeJob;
use App\Shared\Http\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use Tests\TestCase;

class QueueTraceIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->group(function () {
            Route::get('/api/v1/_test/dispatch-trace-probe', function () {
                TraceProbeJob::dispatch();

                return ApiResponse::success(['dispatched' => true]);
            });
        });
    }

    public function test_a_queued_job_logs_the_dispatching_requests_trace_id(): void
    {
        config(['queue.default' => 'database']);

        $handler = new TestHandler;

        /** @var Logger $channel */
        $channel = Log::channel(config('logging.default'));

        /** @var MonologLogger $logger */
        $logger = $channel->getLogger();
        $logger->pushHandler($handler);

        $response = $this->getJson('/api/v1/_test/dispatch-trace-probe')->assertOk();
        $requestId = $response->headers->get('X-Request-Id');

        $this->artisan('queue:work', [
            '--once' => true,
            '--stop-when-empty' => true,
        ]);

        $records = array_filter(
            $handler->getRecords(),
            fn ($record) => $record->message === 'trace_probe.handled',
        );

        $this->assertNotEmpty($records, 'Expected the queued job to log "trace_probe.handled".');

        $record = array_values($records)[0];

        $this->assertSame($requestId, $record->extra['trace_id'] ?? null);
    }
}
