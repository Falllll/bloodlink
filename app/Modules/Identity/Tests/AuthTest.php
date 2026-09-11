<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_a_donor_and_returns_a_bearer_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'budi@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'user' => ['id', 'name', 'email', 'role']],
            ])
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'budi@example.com')
            ->assertJsonPath('data.user.role', 'donor');

        $this->assertNotEmpty($response->json('data.token'));

        $this->assertDatabaseHas('users', [
            'email' => 'budi@example.com',
            'role' => 'donor',
            'facility_id' => null,
            'is_active' => true,
        ]);
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Orang Lain',
            'email' => 'dupe@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(1, User::where('email', 'dupe@example.com')->count());
    }

    public function test_it_returns_the_same_error_for_an_unknown_email_and_a_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => Hash::make('correct-password'),
        ]);

        $unknownEmail = $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'whatever-password',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $unknownEmail->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $wrongPassword->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertSame($unknownEmail->json('error.message'), $wrongPassword->json('error.message'));
    }

    public function test_it_refuses_to_log_in_an_inactive_account(): void
    {
        User::factory()->create([
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'password123',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_only_the_token_used_for_that_request(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $tokenB = $user->createToken('device-b')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/auth/logout', [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        // Sanctum's guard caches the resolved user for the lifetime of the app
        // instance; within a single test that instance is shared across calls,
        // so it must be reset before authenticating as a different token.
        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/auth/logout', [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_protected_route_rejects_a_revoked_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('device-a')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout', [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk();

        Auth::forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout', [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(401);
    }
}
