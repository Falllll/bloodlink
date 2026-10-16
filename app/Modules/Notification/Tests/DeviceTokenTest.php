<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Application\RevokeDeviceToken;
use App\Modules\Notification\Domain\DevicePlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test: guard Sanctum memegang user dari request
 * pertama meski tokennya berganti. Langkah pengguna lain lewat service.
 */
final class DeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function register(): RegisterDeviceToken
    {
        return app(RegisterDeviceToken::class);
    }

    public function test_a_user_without_a_facility_registers_a_token(): void
    {
        // Donor tidak punya fasilitas (kartu 255): rute ini tidak boleh menuntut facility.context.
        $user = User::factory()->create(['facility_id' => null]);

        $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token-A', 'platform' => 'android'], $this->headers($user))
            ->assertCreated()
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.revoked_at', null)
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.user_id');

        $this->assertSame($user->id, DeviceToken::query()->where('token', 'fcm-token-A')->sole()->user_id);
    }

    public function test_registering_the_same_token_again_does_not_add_a_row(): void
    {
        $user = User::factory()->create();
        $headers = $this->headers($user);

        $first = $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token-A', 'platform' => 'android'], $headers)->json('data.id');
        $this->travel(10)->minutes();
        $second = $this->postJson('/api/v1/me/devices', ['token' => 'fcm-token-A', 'platform' => 'android'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertCreated()
            ->json('data');

        $this->assertSame($first, $second['id']);
        $this->assertSame(now()->toAtomString(), $second['last_seen_at']);
        $this->assertSame(1, DeviceToken::query()->count());
    }

    public function test_a_token_registered_by_another_user_moves_to_the_new_owner(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->register()->handle($a, 'T', DevicePlatform::IOS);
        $this->register()->handle($b, 'T', DevicePlatform::IOS);

        $rows = DB::table('device_tokens')->where('token', 'T')->get(['user_id']);
        $this->assertCount(1, $rows);
        $this->assertSame($b->id, $rows[0]->user_id);
        $this->assertSame(0, DeviceToken::query()->where('user_id', $a->id)->deliverable()->count());
    }

    public function test_re_registering_a_revoked_token_reactivates_it(): void
    {
        $user = User::factory()->create();
        $device = $this->register()->handle($user, 'T', DevicePlatform::WEB);
        app(RevokeDeviceToken::class)->handle($device);

        $this->register()->handle($user, 'T', DevicePlatform::WEB);

        $this->assertNull(DeviceToken::query()->sole()->revoked_at);
    }

    public function test_revoking_keeps_the_row_and_removes_it_from_delivery(): void
    {
        $user = User::factory()->create();
        $device = $this->register()->handle($user, 'T', DevicePlatform::ANDROID);

        $this->deleteJson("/api/v1/me/devices/{$device->public_id}", [], $this->headers($user))
            ->assertOk()
            ->assertJsonPath('data.revoked_at', now()->toAtomString());

        $this->assertNotNull(DB::table('device_tokens')->where('token', 'T')->value('revoked_at'));
        $this->assertSame(0, DeviceToken::query()->deliverable()->count());
    }

    public function test_a_user_cannot_revoke_someone_elses_token(): void
    {
        $owner = User::factory()->create();
        $device = $this->register()->handle($owner, 'T', DevicePlatform::ANDROID);

        $this->deleteJson("/api/v1/me/devices/{$device->public_id}", [], $this->headers(User::factory()->create()))
            ->assertNotFound();

        $this->assertNull(DeviceToken::query()->sole()->revoked_at);
    }

    public function test_the_platform_must_be_known(): void
    {
        $this->postJson('/api/v1/me/devices', ['token' => 'T', 'platform' => 'symbian'], $this->headers(User::factory()->create()))
            ->assertStatus(422);

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_registration_requires_authentication(): void
    {
        $this->postJson('/api/v1/me/devices', ['token' => 'T', 'platform' => 'android'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertUnauthorized();
    }

    public function test_a_soft_deleted_account_is_no_longer_deliverable(): void
    {
        $user = User::factory()->create();
        $this->register()->handle($user, 'T', DevicePlatform::ANDROID);

        $user->delete();

        $this->assertSame(1, DB::table('device_tokens')->count());
        $this->assertSame(0, DeviceToken::query()->deliverable()->count());
    }

    public function test_tokens_die_with_their_account(): void
    {
        $user = User::factory()->create();
        $this->register()->handle($user, 'T', DevicePlatform::ANDROID);

        $user->forceDelete();

        $this->assertSame(0, DB::table('device_tokens')->count());
    }

    public function test_the_token_never_appears_in_model_serialisation(): void
    {
        $device = $this->register()->handle(User::factory()->create(), 'secret-T', DevicePlatform::ANDROID);

        $this->assertStringNotContainsString('secret-T', (string) json_encode($device->toArray()));
    }
}
