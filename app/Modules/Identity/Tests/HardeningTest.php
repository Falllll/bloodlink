<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class HardeningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function assignRole(User $user, RoleEnum $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(
            FacilityScope::of($user->facility_id)
        );

        $user->assignRole($role->value);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    public function test_requests_beyond_the_limit_get_429_in_the_standard_envelope(): void
    {
        Route::middleware('api')->get('/api/v1/_test/hardening/rate-limit', function () {
            return ['ok' => true];
        });

        config()->set('security.rate_limit.per_minute', 2);

        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.10',
        ]);

        $this->getJson('/api/v1/_test/hardening/rate-limit')
            ->assertOk();

        $this->getJson('/api/v1/_test/hardening/rate-limit')
            ->assertOk();

        $this->getJson('/api/v1/_test/hardening/rate-limit')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_every_api_response_carries_the_security_headers(): void
    {
        $response = $this->getJson('/api/v1/ping')
            ->assertOk();

        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; form-action 'none'; base-uri 'none';"
            )
            ->assertHeader(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=()'
            )
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
    }

    public function test_the_x_powered_by_header_is_absent(): void
    {
        $response = $this->getJson('/api/v1/ping')
            ->assertOk();

        $this->assertFalse(
            $response->headers->has('X-Powered-By')
        );
    }

    public function test_the_limit_is_keyed_per_user_not_per_ip(): void
    {
        Route::middleware('api')->get('/api/v1/_test/hardening/user-rate-limit', function () {
            return ['ok' => true];
        });

        config()->set('security.rate_limit.per_minute', 2);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $this->actingAs($userA, 'sanctum');

        $this->getJson('/api/v1/_test/hardening/user-rate-limit')
            ->assertOk();

        $this->getJson('/api/v1/_test/hardening/user-rate-limit')
            ->assertOk();

        $this->getJson('/api/v1/_test/hardening/user-rate-limit')
            ->assertStatus(429);

        $this->actingAs($userB, 'sanctum');

        $this->getJson('/api/v1/_test/hardening/user-rate-limit')
            ->assertOk();
    }

    public function test_an_unknown_request_key_is_rejected(): void
    {
        $facility = Facility::factory()->create();

        $admin = User::factory()->create([
            'facility_id' => $facility->id,
        ]);

        $this->assignRole($admin, RoleEnum::ADMIN);

        $this->actingAs($admin, 'sanctum');

        $response = $this->postJson('/api/v1/facilities', [
            'code' => 'TEST-001',
            'name' => 'Test Facility',
            'type' => 'hospital',
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'phone' => '0210000000',
            'email' => 'test@example.com',
            'latitude' => -6.2,
            'longitude' => 106.8,
            'facilty_id' => 1,
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath(
                'error.details.facilty_id.0',
                'The facilty_id field is not allowed.'
            );
    }

    public function test_a_429_response_still_carries_the_security_headers(): void
    {
        Route::middleware('api')->get('/api/v1/_test/hardening/headers-on-429', function () {
            return ['ok' => true];
        });

        config()->set('security.rate_limit.per_minute', 1);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/_test/hardening/headers-on-429')
            ->assertOk();

        $response = $this->getJson('/api/v1/_test/hardening/headers-on-429')
            ->assertStatus(429);

        $response
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; form-action 'none'; base-uri 'none';"
            )
            ->assertHeader(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=()'
            )
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');

        $response->assertHeader('X-Request-Id');

        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('error.trace_id'),
        );
    }

    public function test_an_api_request_without_accept_header_returns_json_unauthenticated_response(): void
    {
        $response = $this->get('/api/v1/blood-batches');

        $response
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonPath('error.message', 'Unauthenticated.')
            ->assertJsonPath('error.trace_id', fn ($traceId) => filled($traceId));
    }

    public function test_a_429_response_preserves_retry_after_header(): void
    {
        Route::middleware('api')->get('/api/v1/_test/hardening/retry-after', function () {
            return ['ok' => true];
        });

        config()->set('security.rate_limit.per_minute', 1);

        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/_test/hardening/retry-after')
            ->assertOk();

        $response = $this->getJson('/api/v1/_test/hardening/retry-after')
            ->assertStatus(429);

        $response->assertHeader('Retry-After');
    }

    public function test_a_405_response_preserves_allow_header(): void
    {
        Route::middleware('api')->get('/api/v1/_test/hardening/method-not-allowed', function () {
            return ['ok' => true];
        });

        $response = $this->postJson('/api/v1/_test/hardening/method-not-allowed')
            ->assertStatus(405);

        $response->assertHeader('Allow');
    }
}
