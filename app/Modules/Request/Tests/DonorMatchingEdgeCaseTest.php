<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\DonorCampaignContact;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\SelectDonorCandidates;
use App\Modules\Request\Application\StartDonorCampaign;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Matching\DonorDirectory;
use App\Shared\Matching\NearbyDonor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Cabang gagal-tertutup pemilihan kandidat donor (kartu 670). Titik asal Monas; 1 derajat
 * lintang ~ 111 km. Yang menyentuh jawaban direktori memakai DonorDirectory palsu, karena
 * implementasi aslinya selalu menilai semua donor yang ia kembalikan.
 */
final class DonorMatchingEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private const float LAT = -6.1754;

    private const float LNG = 106.8272;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);
        $this->seed(DeferralReasonSeeder::class);

        $this->facility = Facility::factory()->create();
        DB::table('facilities')->where('id', $this->facility->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT)),
        ]);
    }

    private function donorAt(float $northDegrees, string $group = 'O', string $rh = 'negative', ?string $lastDonation = null): Donor
    {
        $donor = Donor::factory()->create();
        $donor->forceFill([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'blood_group' => $group,
            'rh_factor' => $rh,
            'last_donation_date' => $lastDonation,
            'email' => 'donor@example.test',
        ])->save();

        DB::table('donors')->where('id', $donor->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $northDegrees)),
        ]);

        return $donor;
    }

    private function request(): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            2,
            RequestUrgency::EMERGENCY,
            new DateTimeImmutable('+2 hours'),
            'RM-'.Str::random(6),
        );
    }

    /** @return TestResponse<Response> */
    private function preview(BloodRequest $request): TestResponse
    {
        $user = User::factory()->create(['facility_id' => $this->facility->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($this->facility->id));
        $user->assignRole('admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $this->getJson(
            "/api/v1/blood-requests/{$request->public_id}/donor-candidates?radius_meters=5000",
            ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken],
        );
    }

    /**
     * Direktori palsu: satu donor dekat, penilaian dan keterjangkauan sesuai yang diberikan.
     *
     * @param  array<int, string|null>  $reasons
     * @param  array<int, bool>  $reachable
     */
    private function fakeDirectory(array $reasons, array $reachable): object
    {
        $fake = new class($reasons, $reachable) implements DonorDirectory
        {
            public ?int $requestedLimit = null;

            /**
             * @param  array<int, string|null>  $reasons
             * @param  array<int, bool>  $reachable
             */
            public function __construct(private readonly array $reasons, private readonly array $reachable) {}

            public function nearest(float $latitude, float $longitude, int $radiusMeters, array $acceptablePairs, array $excludeDonorIds, int $limit): array
            {
                $this->requestedLimit = $limit;

                return [new NearbyDonor(7, 'donor-tujuh', 100)];
            }

            public function ineligibilityReasons(array $donorIds): array
            {
                return $this->reasons;
            }

            public function reachability(array $donorIds): array
            {
                return $this->reachable;
            }
        };

        app()->instance(DonorDirectory::class, $fake);

        return $fake;
    }

    // Celah 1. Donor tanpa penilaian: null di sini berarti LAYAK, jadi "tidak ada" harus menolak.
    public function test_a_donor_missing_from_the_assessment_fails_closed(): void
    {
        $this->fakeDirectory(reasons: [], reachable: [7 => true]);

        [$candidate] = app(SelectDonorCandidates::class)->handle($this->request(), $this->facility, 5000);

        $this->assertFalse($candidate->isEligible);
        $this->assertSame('NOT_ASSESSED', $candidate->ineligibleReasonCode);
        // Terjangkau tidak mengubah apa pun: yang menahan adalah penilaiannya.
        $this->assertTrue($candidate->isReachable);
    }

    // Celah 2. Sisi FASILITAS: donor ada di dekat koordinatnya, tapi fasilitasnya tanpa koordinat.
    public function test_a_facility_without_coordinates_gets_no_candidates(): void
    {
        $this->donorAt(0.010);
        $request = $this->request();
        DB::table('facilities')->where('id', $this->facility->id)->update(['location' => null]);

        $this->assertSame([], app(SelectDonorCandidates::class)->handle($request, $this->facility->fresh() ?? $this->facility, 30000));
        $this->preview($request)->assertStatus(200)->assertJsonPath('data.candidates', []);

        // Kosong saja belum bukti: koordinat NULL yang lolos menjadi pencarian di titik (0, 0),
        // yang juga kosong. Direktorinya tidak boleh ditanya sama sekali.
        $fake = $this->fakeDirectory(reasons: [], reachable: []);
        app(SelectDonorCandidates::class)->handle($request, $this->facility, 30000);
        $this->assertObjectHasProperty('requestedLimit', $fake);
        $this->assertNull($fake->requestedLimit);
    }

    // Celah 3. Kosong adalah jawaban, bukan error: 200, bukan 404 atau 409.
    public function test_an_empty_candidate_list_is_a_200_end_to_end(): void
    {
        $request = $this->request();

        // (a) Tidak ada siapa pun di dalam radius.
        $this->donorAt(1.0);
        $this->preview($request)->assertStatus(200)
            ->assertJsonPath('data.blood_request_id', $request->public_id)
            ->assertJsonPath('data.candidates', []);

        // (b) Ada donor di dalam radius, tapi golongannya tidak kompatibel dengan O negatif.
        $this->donorAt(0.010, 'A', 'positive');
        $this->preview($request)->assertStatus(200)->assertJsonPath('data.candidates', []);
    }

    /**
     * Celah 6, bagian yang benar: direktori memang diminta lebih banyak dari batas kirim.
     * Faktor 3 milik SelectDonorCandidates; yang dipaku adalah kelipatannya.
     */
    public function test_the_directory_is_asked_for_more_donors_than_the_send_limit(): void
    {
        config(['notification.max_recipients_per_campaign' => 4]);
        $fake = $this->fakeDirectory(reasons: [7 => null], reachable: [7 => true]);

        app(SelectDonorCandidates::class)->handle($this->request(), $this->facility, 5000);

        $this->assertObjectHasProperty('requestedLimit', $fake);
        $this->assertSame(12, $fake->requestedLimit);
    }

    /**
     * Celah 6, jawaban atas pertanyaan kartu: donor layak di belakang donor tak layak yang lebih
     * dekat tetap masuk kuota, karena itulah alasan overfetch ada. Kuota diisi yang bisa dipanggil
     * dulu; urutan keluaran tetap jarak.
     */
    public function test_ineligible_nearest_donors_never_crowd_out_the_eligible_ones(): void
    {
        config(['notification.max_recipients_per_campaign' => 2]);
        $recently = now()->subDays(10)->toDateString();
        $this->donorAt(0.001, lastDonation: $recently);
        $this->donorAt(0.002, lastDonation: $recently);
        $eligibleC = $this->donorAt(0.003);
        $eligibleD = $this->donorAt(0.004);
        $request = $this->request();

        $candidates = app(SelectDonorCandidates::class)->handle($request, $this->facility, 5000);

        $this->assertSame([$eligibleC->public_id, $eligibleD->public_id], array_map(fn ($c): string => $c->donorPublicId, $candidates));
        $this->assertSame([true, true], array_map(fn ($c): bool => $c->isEligible, $candidates));

        // Di hulu: dua donor layak dalam radius, kampanye menghubungi keduanya.
        app(StartDonorCampaign::class)->handle($request, User::factory()->create());
        $this->assertSame(2, DonorCampaignContact::query()->count());
    }

    // Kuota yang tidak habis oleh donor layak tetap diisi yang terdekat lainnya, urut jarak --
    // pratinjau tidak kehilangan alasan ketidaklayakan.
    public function test_leftover_slots_are_filled_by_the_nearest_others_in_distance_order(): void
    {
        config(['notification.max_recipients_per_campaign' => 2]);
        $recently = now()->subDays(10)->toDateString();
        $blockedA = $this->donorAt(0.001, lastDonation: $recently);
        $this->donorAt(0.002, lastDonation: $recently);
        $eligibleC = $this->donorAt(0.003);

        $candidates = app(SelectDonorCandidates::class)->handle($this->request(), $this->facility, 5000);

        $this->assertSame([$blockedA->public_id, $eligibleC->public_id], array_map(fn ($c): string => $c->donorPublicId, $candidates));
        $this->assertSame('DONATION_INTERVAL_NOT_MET', $candidates[0]->ineligibleReasonCode);
    }

    // Celah 7. Donor yang tidak ada di peta keterjangkauan dianggap tidak terjangkau.
    public function test_a_donor_missing_from_the_reachability_map_is_not_reachable(): void
    {
        $this->fakeDirectory(reasons: [7 => null], reachable: []);

        [$candidate] = app(SelectDonorCandidates::class)->handle($this->request(), $this->facility, 5000);

        $this->assertTrue($candidate->isEligible);
        $this->assertFalse($candidate->isReachable);
    }
}
