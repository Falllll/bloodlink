<?php

namespace Tests\Feature;

use App\Shared\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApiErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->group(function () {
            Route::get('/api/v1/_test/success', function () {
                return ApiResponse::success([
                    'ok' => true,
                ], ['page' => 1]);
            });

            Route::get('/api/v1/_test/validation', function () {
                throw ValidationException::withMessages([
                    'email' => ['The email field is required.'],
                ]);
            });

            Route::get('/api/v1/_test/unauthenticated', function () {
                throw new AuthenticationException('Unauthenticated.');
            }); 

            Route::get('/api/v1/_test/forbidden', function () {
                throw new AuthorizationException('Forbidden.');
            });

            Route::get('/api/v1/_test/not-found', function () {
                throw new ModelNotFoundException('User not found.');
            });

            Route::get('/api/v1/_test/throttled', function () {
                return ApiResponse::success(['ok' => true]);
            })->middleware('throttle:1,1');
        });
    }

    public function test_it_returns_standard_success_payload(): void
    {
        $this->getJson('/api/v1/_test/success')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['ok'],
                'meta' => ['page'],
            ]);
    }

    public function test_it_returns_validation_failed_payload(): void
    {
        $this->getJson('/api/v1/_test/validation')
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details', 'trace_id'],
            ])
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_it_returns_unauthenticated_payload(): void
    {
        $this->getJson('/api/v1/_test/unauthenticated')
            ->assertStatus(401)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details', 'trace_id'],
            ])
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_it_returns_forbidden_payload(): void
    {
        $this->getJson('/api/v1/_test/forbidden')
            ->assertStatus(403)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details', 'trace_id'],
            ])
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_it_returns_not_found_payload(): void
    {
        $this->getJson('/api/v1/_test/not-found')
            ->assertStatus(404)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details', 'trace_id'],
            ])
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_it_returns_internal_error_payload_without_leaking_details(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/v1/_test/internal')
            ->assertStatus(500)
            ->assertJsonStructure([
                'error' => ['code', 'message', 'details', 'trace_id'],
            ])
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('error.message', 'Internal server error.')
            ->assertJsonMissingPath('error.details.file')
            ->assertJsonMissingPath('error.details.line');
    }
}
