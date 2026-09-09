<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Http\ApiResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->group(function () {
            Route::get('/api/v1/_test/ok', function () {
                return ApiResponse::success(['ok' => true]);
            });

            Route::get('/api/v1/_test/boom', function () {
                throw new \RuntimeException('Boom.');
            });
        });
    }

    public function test_it_generates_a_request_id_when_none_is_sent(): void
    {
        $response = $this->getJson('/api/v1/_test/ok')->assertOk();

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_it_echoes_back_a_client_supplied_request_id(): void
    {
        $response = $this->getJson('/api/v1/_test/ok', ['X-Request-Id' => 'abc-123'])
            ->assertOk();

        $this->assertSame('abc-123', $response->headers->get('X-Request-Id'));
    }

    public function test_it_ignores_an_oversized_request_id_and_generates_its_own(): void
    {
        $oversized = str_repeat('a', 500);

        $response = $this->getJson('/api/v1/_test/ok', ['X-Request-Id' => $oversized])
            ->assertOk();

        $returned = $response->headers->get('X-Request-Id');

        $this->assertNotSame($oversized, $returned);
        $this->assertTrue(Str::isUuid($returned));
    }

    public function test_error_trace_id_matches_the_request_id_header_on_the_same_response(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/_test/boom')->assertStatus(500);

        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('error.trace_id'),
        );
    }
}
