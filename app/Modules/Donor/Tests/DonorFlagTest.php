<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Donor;
use App\Models\DonorFlag;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\DonorEligibilityService;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Flag administratif donor (kartu 430): kanal kedua di sebelah deferral medis.
 * Nama role literal: ModDonor tidak boleh bergantung ke DomIdentity.
 */
final class DonorFlagTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    private int $deferralsBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeferralReasonSeeder::class);

        $this->freezeSecond();
        $this->deferralsBefore = DB::table('deferrals')->count();
    }

    /** Nol baris baru di deferrals sepanjang setiap test: flag tidak pernah menyusup ke kanal medis. */
    protected function tearDown(): void
    {
        // finally: kalau assertion ini gagal, rollback RefreshDatabase TETAP harus jalan --
        // transaksi yang tertinggal memegang kunci dan membuat test berikutnya menunggu selamanya.
        try {
            $this->assertSame($this->deferralsBefore, DB::table('deferrals')->count());
        } finally {
            parent::tearDown();
        }
    }

    private function user(string $role, ?Facility $facility = null): User
    {
        $user = User::factory()->create(['facility_id' => $facility?->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    /** Donor dewasa, cukup berat, belum pernah donor: layak menurut mesin kelayakan. */
    private function eligibleDonor(?Facility $facility = null): Donor
    {
        $donor = Donor::factory()->create(['registered_facility_id' => ($facility ?? Facility::factory()->create())->id]);
        $donor->forceFill([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'last_donation_date' => null,
        ])->save();

        return $donor;
    }

    /** @return list<string> */
    private function findingCodes(Donor $donor): array
    {
        $decision = DonorEligibilityService::who()->for(Donor::query()->findOrFail($donor->id));

        return array_map(fn (EligibilityFinding $finding): string => $finding->code->value, $decision->findings);
    }

    private function outcome(Donor $donor): EligibilityOutcome
    {
        return DonorEligibilityService::who()->for(Donor::query()->findOrFail($donor->id))->outcome;
    }

    /** @param  array<string, mixed>  $overrides */
    private function place(Donor $donor, array $overrides = []): DonorFlag
    {
        $flag = new DonorFlag;
        $flag->forceFill([
            'reason' => 'repeated_no_show',
            'note' => 'Tidak datang tiga kali tanpa kabar.',
            'starts_at' => now(),
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
        ] + $overrides)->save();

        return $flag;
    }

    public function test_a_global_operator_places_a_flag_that_blocks_with_its_own_code(): void
    {
        $donor = $this->eligibleDonor();
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->outcome($donor));

        $this->postJson("/api/v1/donors/{$donor->public_id}/flags", [
            'reason' => 'identity_fraud',
            'note' => 'Dokumen identitas palsu saat skrining.',
        ], $this->headers($this->user('admin')))
            ->assertCreated()
            ->assertJsonPath('data.donor_id', $donor->public_id)
            ->assertJsonPath('data.reason', 'identity_fraud')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.placed_by');

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $this->outcome($donor));
        $this->assertSame(['ACTIVE_ADMIN_FLAG'], $this->findingCodes($donor));
        $this->assertNotContains('ACTIVE_DEFERRAL', $this->findingCodes($donor));
    }

    public function test_a_lifted_flag_makes_the_donor_eligible_again(): void
    {
        $donor = $this->eligibleDonor();
        $flag = $this->place($donor);
        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $this->outcome($donor));

        $this->postJson("/api/v1/donor-flags/{$flag->public_id}/lift", ['reason' => 'Sudah klarifikasi.'], $this->headers($this->user('admin')))
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.lift_note', 'Sudah klarifikasi.');

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->outcome($donor));
    }

    /** Tanpa batas waktu bukan berarti selamanya: tetap bisa dicabut. */
    public function test_a_flag_without_an_end_can_still_be_lifted(): void
    {
        $donor = $this->eligibleDonor();
        $flag = $this->place($donor);
        $this->assertNull($flag->ends_at);

        $this->postJson("/api/v1/donor-flags/{$flag->public_id}/lift", ['reason' => 'Dicabut.'], $this->headers($this->user('admin')))
            ->assertOk();

        $this->assertNotNull($flag->fresh()?->lifted_at);
    }

    public function test_an_expired_flag_no_longer_blocks(): void
    {
        $donor = $this->eligibleDonor();
        $this->place($donor, ['ends_at' => now()->subHour()]);

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->outcome($donor));
    }

    public function test_a_timed_flag_carries_its_end_as_retry_date(): void
    {
        $donor = $this->eligibleDonor();
        $this->place($donor, ['ends_at' => now()->addDays(30)]);

        $decision = DonorEligibilityService::who()->for(Donor::query()->findOrFail($donor->id));

        $this->assertSame(now()->addDays(30)->toDateString(), $decision->retryAfterDate?->format('Y-m-d'));
    }

    public function test_a_second_active_flag_is_409(): void
    {
        $donor = $this->eligibleDonor();
        $this->place($donor);

        $this->postJson("/api/v1/donors/{$donor->public_id}/flags", ['reason' => 'other', 'note' => 'Lagi.'], $this->headers($this->user('admin')))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_FLAG_REJECTED');

        $this->assertSame(1, DonorFlag::query()->count());
    }

    /** Flag yang sudah DICABUT bukan flag aktif: penempatan berikutnya boleh. */
    public function test_a_new_flag_can_be_placed_once_the_previous_one_is_lifted(): void
    {
        $donor = $this->eligibleDonor();
        $old = $this->place($donor);
        $old->forceFill(['lifted_at' => now(), 'lift_note' => 'Selesai.'])->save();

        $this->postJson("/api/v1/donors/{$donor->public_id}/flags", ['reason' => 'staff_safety', 'note' => 'Mengancam petugas.'], $this->headers($this->user('admin')))
            ->assertCreated();

        $this->assertSame(2, DonorFlag::query()->where('donor_id', $donor->id)->count());
    }

    public function test_lifting_twice_is_409_and_leaves_the_row_untouched(): void
    {
        $donor = $this->eligibleDonor();
        $flag = $this->place($donor);
        $headers = $this->headers($this->user('admin'));

        $this->postJson("/api/v1/donor-flags/{$flag->public_id}/lift", ['reason' => 'Pertama.'], $headers)->assertOk();
        $updatedAt = DB::table('donor_flags')->where('id', $flag->id)->value('updated_at');

        $this->travel(5)->minutes();

        $this->postJson("/api/v1/donor-flags/{$flag->public_id}/lift", ['reason' => 'Kedua.'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_FLAG_REJECTED');

        $this->assertSame($updatedAt, DB::table('donor_flags')->where('id', $flag->id)->value('updated_at'));
        $this->assertSame('Pertama.', $flag->fresh()?->lift_note);
    }

    /** Donor SELALU dari path: donor_id di body ditolak, dan donor lain tidak tersentuh. */
    public function test_a_donor_id_in_the_body_never_redirects_the_flag(): void
    {
        $target = $this->eligibleDonor();
        $victim = $this->eligibleDonor();

        $this->postJson("/api/v1/donors/{$target->public_id}/flags", [
            'reason' => 'other',
            'note' => 'Coba alihkan.',
            'donor_id' => $victim->public_id,
        ], $this->headers($this->user('admin')))
            ->assertStatus(422);

        $this->assertSame(0, DonorFlag::query()->where('donor_id', $victim->id)->count());
        $this->assertSame(0, DonorFlag::query()->count());
    }

    public function test_a_note_is_required(): void
    {
        $donor = $this->eligibleDonor();

        $this->postJson("/api/v1/donors/{$donor->public_id}/flags", ['reason' => 'other'], $this->headers($this->user('admin')))
            ->assertStatus(422);
    }

    /** Admin yang terikat fasilitas bukan operator global. */
    public function test_a_facility_bound_admin_cannot_place_or_lift(): void
    {
        $facility = Facility::factory()->create();
        $donor = $this->eligibleDonor($facility);
        $flag = $this->place($donor);
        $headers = $this->headers($this->user('admin', $facility));

        $this->postJson("/api/v1/donors/{$donor->public_id}/flags", ['reason' => 'other', 'note' => 'X.'], $headers)->assertForbidden();
        $this->postJson("/api/v1/donor-flags/{$flag->public_id}/lift", ['reason' => 'X.'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)->assertForbidden();

        $this->assertNull($flag->fresh()?->lifted_at);
    }

    public function test_staff_at_the_donors_facility_can_list_and_filter_active_flags(): void
    {
        $facility = Facility::factory()->create();
        $donor = $this->eligibleDonor($facility);
        $this->place($donor, ['ends_at' => now()->subDay()]);
        $this->place($donor, ['ends_at' => now()->addDays(2), 'lifted_at' => now(), 'lift_note' => 'Dicabut.']);
        $active = $this->place($donor, ['ends_at' => now()->addDay()]);
        $headers = $this->headers($this->user('hospital_staff', $facility));

        $this->getJson("/api/v1/donors/{$donor->public_id}/flags", $headers)
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->getJson("/api/v1/donors/{$donor->public_id}/flags?filter[active]=1", $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->public_id);
    }

    public function test_staff_elsewhere_cannot_list(): void
    {
        $donor = $this->eligibleDonor();

        $this->getJson("/api/v1/donors/{$donor->public_id}/flags", $this->headers($this->user('hospital_staff', Facility::factory()->create())))
            ->assertForbidden();
    }

    public function test_the_database_ties_lift_details_to_lifted_at(): void
    {
        $flag = $this->place($this->eligibleDonor());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donor_flags_lift_shape');

        // Savepoint: pelanggaran CHECK membatalkan transaksi luar di Postgres.
        DB::transaction(fn () => DB::table('donor_flags')->where('id', $flag->id)->update(['lift_note' => 'Tanpa pencabutan.']));
    }

    public function test_many_donors_are_assessed_without_one_query_per_flag(): void
    {
        $count = function (int $donors): int {
            $ids = [];
            foreach (range(1, $donors) as $_) {
                $donor = $this->eligibleDonor();
                $this->place($donor);
                $ids[] = $donor->id;
            }

            return $this->countQueries(fn () => DonorEligibilityService::who()->forMany(Donor::query()->whereKey($ids)->get()));
        };

        $this->assertSame($count(3), $count(20));
    }
}
