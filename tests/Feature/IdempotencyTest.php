<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Http\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post('/api/v1/_test/create', function () {
            DB::table('facilities')->insert([
                'public_id' => (string) Str::uuid(),
                'code' => 'FAC-'.Str::random(8),
                'name' => 'Test Facility',
                'type' => 'hospital',
                'address' => 'Jl. Test',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'phone' => '0210000000',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ApiResponse::success(['created' => true]);
        });

        Route::middleware('api')->post('/api/v1/_test/fail', function () {
            throw new \RuntimeException('Boom.');
        });
    }

    public function test_it_replays_the_stored_response_for_a_repeated_key(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/v1/_test/create', [], ['Idempotency-Key' => $key])
            ->assertOk();

        $this->assertFalse($first->headers->has('Idempotency-Replayed'));

        $second = $this->postJson('/api/v1/_test/create', [], ['Idempotency-Key' => $key])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(1, DB::table('facilities')->count());
    }

    public function test_it_returns_conflict_while_the_original_request_is_still_in_progress(): void
    {
        $key = (string) Str::uuid();

        DB::table('idempotency_keys')->insert([
            'id' => (string) Str::uuid(),
            'key' => $key,
            'user_id' => null,
            'endpoint' => 'POST _test/create',
            'request_hash' => hash('sha256', json_encode([])),
            'response_status' => null,
            'response_body' => null,
            'locked_at' => now(),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/_test/create', [], ['Idempotency-Key' => $key])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'REQUEST_IN_PROGRESS');
    }

    public function test_it_rejects_the_same_key_reused_with_a_different_body(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/_test/create', ['a' => 1], ['Idempotency-Key' => $key])
            ->assertOk();

        $this->postJson('/api/v1/_test/create', ['a' => 2], ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_it_does_not_leave_a_claim_behind_after_a_server_error(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/_test/fail', [], ['Idempotency-Key' => $key])
            ->assertStatus(500);

        $this->assertSame(0, DB::table('idempotency_keys')->where('key', $key)->count());

        $this->postJson('/api/v1/_test/fail', [], ['Idempotency-Key' => $key])
            ->assertStatus(500);
    }

    public function test_it_requires_the_idempotency_key_header_on_write_requests(): void
    {
        $this->postJson('/api/v1/_test/create')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }
}
