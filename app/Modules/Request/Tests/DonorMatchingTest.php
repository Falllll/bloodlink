<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\DeviceToken;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\SelectDonorCandidates;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
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
 * Pemilihan kandidat donor (kartu 410). Titik asal Monas, sama dengan fixture
 * UrgentRequestTest: 1 derajat lintang ~ 111 km, jadi +0.027 ~ 3 km, +0.072 ~ 8 km.
 * Satu user terautentikasi per test.
 */
final class DonorMatchingTest extends TestCase
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

    /** Donor dewasa, cukup berat, belum pernah donor: layak menurut mesin kelayakan. */
    private function donorAt(?float $northDegrees, string $group = 'O', string $rh = 'negative', ?string $email = 'donor@example.test'): Donor
    {
        $donor = Donor::factory()->create();
        $donor->forceFill([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'blood_group' => $group,
            'rh_factor' => $rh,
            'last_donation_date' => null,
            'email' => $email,
        ])->save();

        if ($northDegrees !== null) {
            DB::table('donors')->where('id', $donor->id)->update([
                'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $northDegrees)),
            ]);
        }

        return $donor;
    }

    private function request(string $group = 'O', string $rh = 'negative', string $component = 'packed_red_cells'): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::from($component),
            BloodGroup::from($group),
            RhFactor::from($rh),
            2,
            RequestUrgency::EMERGENCY,
            new DateTimeImmutable('+2 hours'),
            'RM-'.Str::random(6),
        );
    }

    private function user(string $role = 'admin'): User
    {
        $user = User::factory()->create(['facility_id' => $this->facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($this->facility->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return TestResponse<Response> */
    private function preview(BloodRequest $request, int|string $radius = 5000, string $role = 'admin'): TestResponse
    {
        return $this->getJson(
            "/api/v1/blood-requests/{$request->public_id}/donor-candidates?radius_meters={$radius}",
            ['Authorization' => 'Bearer '.$this->user($role)->createToken('api')->plainTextToken],
        );
    }

    /** @return list<string> */
    private function ids(BloodRequest $request, int $radius = 5000): array
    {
        return array_map(
            fn ($candidate): string => $candidate->donorPublicId,
            app(SelectDonorCandidates::class)->handle($request, $this->facility, $radius),
        );
    }

    public function test_a_donor_three_km_away_is_in_five_km_and_one_eight_km_away_is_not(): void
    {
        $near = $this->donorAt(0.027);
        $far = $this->donorAt(0.072);

        $response = $this->preview($this->request())->assertOk()
            ->assertJsonPath('data.radius_meters', 5000)
            ->assertJsonPath('data.candidates.0.donor_id', $near->public_id)
            ->assertJsonPath('data.candidates.0.is_eligible', true)
            ->assertJsonPath('data.candidates.0.ineligible_reason_code', null)
            ->assertJsonPath('data.candidates.0.is_reachable', true);

        $this->assertCount(1, $response->json('data.candidates'));
        $this->assertNotContains($far->public_id, array_column($response->json('data.candidates'), 'donor_id'));
    }

    public function test_candidates_come_nearest_first(): void
    {
        $c = $this->donorAt(0.040);
        $a = $this->donorAt(0.010);
        $b = $this->donorAt(0.020);

        $this->assertSame([$a->public_id, $b->public_id, $c->public_id], $this->ids($this->request()));
    }

    /** Arah DONOR -> PASIEN. Kalau keduanya lolos atau keduanya gagal, arahnya terbalik. */
    public function test_compatibility_runs_from_donor_to_patient(): void
    {
        $aPositive = $this->donorAt(0.009, 'A', 'positive');
        $oNegative = $this->donorAt(0.009, 'O', 'negative');

        $forONegativePatient = $this->ids($this->request('O', 'negative'));
        $forAbPositivePatient = $this->ids($this->request('AB', 'positive'));

        $this->assertNotContains($aPositive->public_id, $forONegativePatient);
        $this->assertContains($oNegative->public_id, $forAbPositivePatient);
    }

    public function test_an_ineligible_donor_is_returned_with_the_reason(): void
    {
        $recent = $this->donorAt(0.010);
        $recent->forceFill(['last_donation_date' => now()->subDays(10)->toDateString()])->save();

        $this->preview($this->request())->assertOk()
            ->assertJsonPath('data.candidates.0.donor_id', $recent->public_id)
            ->assertJsonPath('data.candidates.0.is_eligible', false)
            ->assertJsonPath('data.candidates.0.ineligible_reason_code', 'DONATION_INTERVAL_NOT_MET');
    }

    public function test_a_donor_without_location_never_appears(): void
    {
        $nowhere = $this->donorAt(null);
        $this->donorAt(0.010);

        $this->assertNotContains($nowhere->public_id, $this->ids($this->request()));
    }

    public function test_excluded_donors_do_not_appear(): void
    {
        $contacted = $this->donorAt(0.010);
        $fresh = $this->donorAt(0.020);

        $ids = array_map(
            fn ($candidate): string => $candidate->donorPublicId,
            app(SelectDonorCandidates::class)->handle($this->request(), $this->facility, 5000, [$contacted->id]),
        );

        $this->assertSame([$fresh->public_id], $ids);
    }

    public function test_reachability_needs_an_active_device_token_or_an_email(): void
    {
        $unreachable = $this->donorAt(0.010, email: null);
        $withToken = $this->donorAt(0.020, email: null);
        $revokedOnly = $this->donorAt(0.030, email: null);

        foreach ([[$withToken, null], [$revokedOnly, now()]] as [$donor, $revokedAt]) {
            $user = User::factory()->create();
            $donor->forceFill(['user_id' => $user->id])->save();
            $token = new DeviceToken;
            $token->forceFill([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'token' => 'T-'.Str::random(10),
                'platform' => 'android',
                'last_seen_at' => now(),
                'revoked_at' => $revokedAt,
            ])->save();
        }

        $byId = array_column($this->preview($this->request())->assertOk()->json('data.candidates'), 'is_reachable', 'donor_id');

        $this->assertSame([
            $unreachable->public_id => false,
            $withToken->public_id => true,
            $revokedOnly->public_id => false,
        ], $byId);
    }

    public function test_the_result_never_exceeds_the_campaign_cap(): void
    {
        config(['notification.max_recipients_per_campaign' => 3]);
        foreach (range(1, 5) as $i) {
            $this->donorAt(0.005 * $i);
        }

        $this->assertCount(3, $this->ids($this->request()));
    }

    public function test_a_candidate_carries_no_contact_data(): void
    {
        $donor = $this->donorAt(0.010);

        $response = $this->preview($this->request())->assertOk();

        $this->assertSame(
            ['donor_id', 'distance_meters', 'is_eligible', 'ineligible_reason_code', 'is_reachable'],
            array_keys($response->json('data.candidates.0')),
        );
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($donor->phone, $body);
        $this->assertStringNotContainsString($donor->full_name, $body);
        $this->assertStringNotContainsString('donor@example.test', $body);
    }

    /** Tidak ada N+1: jumlah query tetap, berapa pun banyaknya donor dalam radius. */
    public function test_the_query_count_does_not_grow_with_the_number_of_donors(): void
    {
        $count = function (int $donors): int {
            DB::table('donors')->delete();
            foreach (range(1, $donors) as $i) {
                $this->donorAt(0.0005 * $i);
            }
            $request = $this->request();

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(SelectDonorCandidates::class)->handle($request, $this->facility, 15000);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->assertSame($count(5), $count(50));
    }

    public function test_an_arbitrary_radius_is_422(): void
    {
        $this->preview($this->request(), 7000)->assertStatus(422);
    }

    public function test_hospital_staff_and_donors_cannot_preview(): void
    {
        $this->preview($this->request(), role: 'hospital_staff')->assertForbidden();
    }

    public function test_a_donor_account_cannot_preview(): void
    {
        $this->preview($this->request(), role: 'donor')->assertForbidden();
    }

    /** Trombosit tanpa aturan bersumber: tidak ada donor yang sah, bukan "semua". */
    public function test_platelets_return_no_candidates(): void
    {
        $this->donorAt(0.010);

        $this->assertSame([], $this->ids($this->request(component: 'platelet_concentrate')));
    }

    public function test_the_candidate_query_uses_the_spatial_index(): void
    {
        DB::statement('SET enable_seqscan = off;');

        $query = DB::table('donors')
            ->whereNotNull('location')
            ->whereRaw('ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)', [self::LNG, self::LAT, 5000]);
        $plan = implode("\n", array_map(
            fn (object $row): string => (string) $row->{'QUERY PLAN'},
            DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()),
        ));

        $this->assertStringContainsString('donors_location_gist', $plan);
    }
}
