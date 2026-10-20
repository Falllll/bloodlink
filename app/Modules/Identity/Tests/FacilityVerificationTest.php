<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Verifikasi fasilitas (kartu 430): "boleh ikut sistem", terpisah dari is_active. */
final class FacilityVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->freezeSecond();
    }

    private function user(RoleEnum $role, ?Facility $facility = null): User
    {
        $user = User::factory()->create(['facility_id' => $facility?->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    public function test_a_new_facility_starts_unverified(): void
    {
        $facility = Facility::factory()->create();

        $this->assertNull($facility->fresh()?->verified_at);
        $this->assertFalse($facility->fresh()->toApiArray()['is_verified']);
    }

    public function test_a_global_operator_verifies_a_facility(): void
    {
        $facility = Facility::factory()->create();
        $admin = $this->user(RoleEnum::ADMIN);

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", ['note' => 'Izin operasional diperiksa.'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.verified_at', now()->toIso8601String())
            ->assertJsonMissingPath('data.verified_by');

        $row = DB::table('facilities')->where('id', $facility->id)->first();
        $this->assertSame($admin->id, (int) $row?->verified_by);
        $this->assertSame('Izin operasional diperiksa.', $row->verification_note);
    }

    public function test_verifying_twice_is_409_and_keeps_the_first_verifier(): void
    {
        $facility = Facility::factory()->create();
        $admin = $this->user(RoleEnum::ADMIN);
        $headers = $this->headers($admin);

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", [], $headers)->assertOk();
        $this->travel(5)->minutes();

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", ['note' => 'Lagi.'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertStatus(409);

        $this->assertNull(DB::table('facilities')->where('id', $facility->id)->value('verification_note'));
    }

    /** Admin yang terikat fasilitas bukan operator global -- bahkan untuk fasilitasnya sendiri. */
    public function test_a_facility_bound_admin_cannot_verify(): void
    {
        $facility = Facility::factory()->create();

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", [], $this->headers($this->user(RoleEnum::ADMIN, $facility)))
            ->assertForbidden();

        $this->assertNull($facility->fresh()?->verified_at);
    }

    public function test_hospital_staff_cannot_verify(): void
    {
        $facility = Facility::factory()->create();

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", [], $this->headers($this->user(RoleEnum::HOSPITAL_STAFF, $facility)))
            ->assertForbidden();
    }

    /** verified_by diisi server; dikirim lewat body ditolak, bukan dipercaya. */
    public function test_the_verifier_cannot_be_supplied_by_the_client(): void
    {
        $facility = Facility::factory()->create();
        $other = User::factory()->create();

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", ['verified_by' => $other->id], $this->headers($this->user(RoleEnum::ADMIN)))
            ->assertStatus(422);

        $this->assertNull($facility->fresh()?->verified_at);
    }

    /** Verifikasi dan operasional dua hal: menonaktifkan lalu mengaktifkan tidak menyentuh verifikasi. */
    public function test_deactivating_and_reactivating_keeps_the_verification(): void
    {
        $facility = Facility::factory()->create();
        $headers = $this->headers($this->user(RoleEnum::ADMIN));

        $this->postJson("/api/v1/facilities/{$facility->public_id}/verify", [], $headers)->assertOk();
        $verifiedAt = DB::table('facilities')->where('id', $facility->id)->value('verified_at');

        $this->patchJson("/api/v1/facilities/{$facility->public_id}/deactivate", [], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.is_verified', true);

        $this->postJson("/api/v1/facilities/{$facility->public_id}/activate", [], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.is_verified', true);

        $this->assertSame($verifiedAt, DB::table('facilities')->where('id', $facility->id)->value('verified_at'));
    }

    public function test_hospital_staff_cannot_reactivate(): void
    {
        $facility = Facility::factory()->create(['is_active' => false]);

        $this->postJson("/api/v1/facilities/{$facility->public_id}/activate", [], $this->headers($this->user(RoleEnum::HOSPITAL_STAFF, $facility)))
            ->assertForbidden();

        $this->assertFalse((bool) $facility->fresh()?->is_active);
    }

    public function test_the_database_rejects_a_verifier_without_a_verification(): void
    {
        $facility = Facility::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('facilities_verification_shape');

        $verifier = User::factory()->create();

        // Savepoint: pelanggaran CHECK membatalkan transaksi luar di Postgres.
        DB::transaction(fn () => DB::table('facilities')->where('id', $facility->id)->update(['verified_by' => $verifier->id]));
    }
}
