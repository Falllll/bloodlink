<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use App\Modules\Donor\Application\FindSimilarDonors;
use App\Modules\Donor\Application\MergeDonors;
use App\Modules\Donor\Application\RegisterDonor;
use App\Shared\Auth\FacilityScope;
use App\Shared\Errors\ErrorCode;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DonorIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** Nama role sengaja literal: ModDonor tidak boleh bergantung ke DomIdentity. */
    private function assignRole(User $user, string $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($user->facility_id));
        $user->assignRole($role);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(User $user): array
    {
        $token = $user->createToken('api')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withIdempotencyKey(array $headers): array
    {
        return array_merge($headers, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDonor(Facility $facility, array $overrides = []): Donor
    {
        $donor = new Donor(array_merge([
            'full_name' => 'Test Donor',
            'date_of_birth' => '1990-01-01',
            'sex' => 'male',
            'phone' => fake()->numerify('08##########'),
            'address' => 'Jl. Test',
            'city' => 'Jakarta',
        ], $overrides));

        $donor->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_number' => 'D-'.Str::upper(Str::random(8)),
        ])->assignFacility($facility->id);

        return $donor;
    }

    public function test_the_same_nik_written_two_ways_hashes_identically(): void
    {
        $this->assertSame(
            Donor::nikHash('3171 0101-9000 0001'),
            Donor::nikHash('3171010190000001'),
        );
    }

    public function test_registering_a_second_donor_with_the_same_nik_returns_a_domain_error(): void
    {
        $facility = Facility::factory()->create();
        $nik = '3171010190000001';

        (new RegisterDonor)->handle($this->makeDonor($facility, ['nik' => $nik]));

        try {
            (new RegisterDonor)->handle($this->makeDonor($facility, ['nik' => $nik]));
            $this->fail('Expected DonorIdentityConflict to be thrown.');
        } catch (DonorIdentityConflict $e) {
            $this->assertSame(ErrorCode::DONOR_DUPLICATE_NIK, $e->errorCode());
        }
    }

    public function test_registering_a_second_donor_with_the_same_phone_in_another_format_is_rejected(): void
    {
        $facility = Facility::factory()->create();

        (new RegisterDonor)->handle($this->makeDonor($facility, ['phone' => '081234567890']));

        try {
            (new RegisterDonor)->handle($this->makeDonor($facility, ['phone' => '+62 812-3456-7890']));
            $this->fail('Expected DonorIdentityConflict to be thrown.');
        } catch (DonorIdentityConflict $e) {
            $this->assertSame(ErrorCode::DONOR_DUPLICATE_PHONE, $e->errorCode());
        }
    }

    public function test_the_database_rejects_a_duplicate_nik_even_without_the_service(): void
    {
        $facility = Facility::factory()->create();
        $nik = '3171010190000002';

        Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => $nik]);

        $this->expectException(UniqueConstraintViolationException::class);

        Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => $nik]);
    }

    public function test_a_similar_name_with_the_same_birth_date_is_a_candidate_not_a_block(): void
    {
        $facility = Facility::factory()->create();
        $dob = '1990-05-15';

        $first = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'full_name' => 'Budi Santoso',
            'date_of_birth' => $dob,
        ]);

        $candidates = (new FindSimilarDonors)->forIdentity('Budi S.', Carbon::parse($dob));

        $this->assertNotEmpty($candidates);
        $this->assertGreaterThanOrEqual(FindSimilarDonors::THRESHOLD, $candidates[0]['score']);
        $this->assertSame($first->public_id, $candidates[0]['id']);

        // Kandidat bersifat sekadar saran: menyimpan donor kedua tetap berhasil.
        $second = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'full_name' => 'Budi S.',
            'date_of_birth' => $dob,
        ]);

        $this->assertDatabaseHas('donors', ['id' => $second->id]);
    }

    public function test_merge_keeps_both_rows_and_writes_an_audit_entry(): void
    {
        $facility = Facility::factory()->create();
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, 'admin');

        $response = $this->postJson('/api/v1/donors/merge', [
            'source_id' => $source->public_id,
            'target_id' => $target->public_id,
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertOk()
            ->assertJsonPath('data.id', $target->public_id)
            ->assertJsonPath('data.merged_id', $source->public_id);

        $this->assertDatabaseHas('donors', ['id' => $source->id, 'merged_into_id' => $target->id]);
        $this->assertDatabaseHas('donors', ['id' => $target->id, 'merged_into_id' => null]);

        $audit = AuditLog::query()
            ->where('auditable_type', Donor::class)
            ->where('auditable_id', $source->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertArrayHasKey('merged_into_id', $audit->changes['after']);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_merged_donor_carries_the_latest_donation_date_for_the_interval_rule(): void
    {
        $facility = Facility::factory()->create();
        $nik = '3171010190000003';

        $source = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'nik' => $nik,
            'last_donation_date' => now()->subDays(20),
            'donation_count' => 2,
        ]);
        $target = Donor::factory()->create([
            'registered_facility_id' => $facility->id,
            'last_donation_date' => now()->subDays(200),
            'donation_count' => 3,
        ]);

        $merged = (new MergeDonors)->handle($source, $target);

        $this->assertNotNull($merged->last_donation_date);
        $this->assertTrue($merged->last_donation_date->isSameDay(now()->subDays(20)));
        $this->assertSame(5, $merged->donation_count);

        $resolved = Donor::query()
            ->where('nik_hash', Donor::nikHash($nik))
            ->whereNull('merged_into_id')
            ->first();

        $this->assertNotNull($resolved);
        $this->assertSame($target->id, $resolved->id);
    }

    public function test_merging_two_donors_with_different_niks_is_rejected(): void
    {
        $facility = Facility::factory()->create();
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => '3171010190000004']);
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => '3171010190000005']);

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, 'admin');

        $response = $this->postJson('/api/v1/donors/merge', [
            'source_id' => $source->public_id,
            'target_id' => $target->public_id,
        ], $this->withIdempotencyKey($this->bearer($admin)));

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_MERGE_CONFLICT');

        $this->assertDatabaseHas('donors', ['id' => $source->id, 'merged_into_id' => null]);
        $this->assertDatabaseHas('donors', ['id' => $target->id, 'merged_into_id' => null]);
    }

    public function test_a_soft_deleted_donor_does_not_block_re_registration(): void
    {
        $facility = Facility::factory()->create();
        $nik = '3171010190000006';

        $first = (new RegisterDonor)->handle($this->makeDonor($facility, ['nik' => $nik]));
        $first->delete();

        $second = (new RegisterDonor)->handle($this->makeDonor($facility, ['nik' => $nik]));

        $this->assertNotSame($first->id, $second->id);
        $this->assertDatabaseHas('donors', ['id' => $second->id, 'nik_hash' => Donor::nikHash($nik)]);
    }

    public function test_hospital_staff_cannot_merge(): void
    {
        $facility = Facility::factory()->create();
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, 'hospital_staff');

        $response = $this->postJson('/api/v1/donors/merge', [
            'source_id' => $source->public_id,
            'target_id' => $target->public_id,
        ], $this->withIdempotencyKey($this->bearer($staff)));

        $response->assertStatus(403);
    }
}
