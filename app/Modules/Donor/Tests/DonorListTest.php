<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\FindSimilarDonors;
use App\Modules\Donor\Application\LiftDeferral;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Application\SummariseDeferralStatus;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\DonorNameSimilarity;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test: dalam satu test, guard Sanctum tetap
 * memegang user request pertama meski token berganti.
 */
final class DonorListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeferralReasonSeeder::class);
    }

    /** Nama role sengaja literal: ModDonor tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    private function defer(Donor $donor, string $code, string $anchor = '2026-01-01'): void
    {
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', $code)->firstOrFail();

        (new PlaceDeferral)->handle($donor, $reason, new DateTimeImmutable($anchor), DeferralSource::MANUAL);
    }

    public function test_the_list_is_paginated_and_never_carries_contact_or_health_data(): void
    {
        $facility = Facility::factory()->create();
        Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => '3171010190001111', 'weight_kg' => 70]);
        Donor::factory()->create(['registered_facility_id' => $facility->id, 'nik' => '3171010190002222', 'weight_kg' => 65]);

        $response = $this->getJson('/api/v1/donors', $this->bearer($this->userOf($facility->id)));

        $response->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['data', 'links', 'meta']);

        foreach ($response->json('data') as $row) {
            foreach (['phone', 'email', 'address', 'weight_kg', 'nik', 'nik_hash', 'phone_hash', 'has_nik', 'date_of_birth'] as $key) {
                $this->assertArrayNotHasKey($key, $row);
            }
            $this->assertIsString($row['id']);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $row['id']);
        }
    }

    public function test_search_finds_a_similar_but_not_identical_name_with_the_dedup_threshold(): void
    {
        $this->assertSame(DonorNameSimilarity::THRESHOLD, FindSimilarDonors::THRESHOLD);

        $facility = Facility::factory()->create();
        $budi = Donor::factory()->create(['registered_facility_id' => $facility->id, 'full_name' => 'Budi Santoso']);
        Donor::factory()->create(['registered_facility_id' => $facility->id, 'full_name' => 'Siti Rahmawati']);

        $response = $this->getJson('/api/v1/donors?search='.urlencode('Budi Santosa'), $this->bearer($this->userOf($facility->id)));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $budi->public_id);
    }

    public function test_deferral_status_covers_none_temporary_permanent_and_ignores_lifted_rows(): void
    {
        $facility = Facility::factory()->create();
        $clear = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $temporary = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $permanent = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $lifted = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $this->defer($temporary, 'PREGNANT_OR_LACTATING');
        $this->defer($permanent, 'TTI_CONFIRMED_REACTIVE');
        $this->defer($permanent, 'PREGNANT_OR_LACTATING'); // permanen mengalahkan temporer
        $this->defer($lifted, 'PREGNANT_OR_LACTATING');
        (new LiftDeferral)->handle($lifted->deferrals()->sole(), User::factory()->create()->id, 'Sudah melahirkan dan selesai menyusui.');

        $response = $this->getJson('/api/v1/donors', $this->bearer($this->userOf($facility->id)));

        $statuses = array_column($response->json('data'), 'deferral_status', 'id');
        $this->assertSame(SummariseDeferralStatus::NONE, $statuses[$clear->public_id]);
        $this->assertSame(SummariseDeferralStatus::TEMPORARY, $statuses[$temporary->public_id]);
        $this->assertSame(SummariseDeferralStatus::PERMANENT, $statuses[$permanent->public_id]);
        $this->assertSame(SummariseDeferralStatus::NONE, $statuses[$lifted->public_id]);
    }

    public function test_an_expired_deferral_no_longer_counts(): void
    {
        $facility = Facility::factory()->create();
        $donor = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $this->defer($donor, 'DENTAL_SIMPLE', '2026-01-01 08:00:00'); // 24 jam, sudah lama lewat

        $this->getJson('/api/v1/donors', $this->bearer($this->userOf($facility->id)))
            ->assertJsonPath('data.0.deferral_status', SummariseDeferralStatus::NONE);
    }

    public function test_filter_by_deferral_status(): void
    {
        $facility = Facility::factory()->create();
        Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $permanent = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $this->defer($permanent, 'TTI_CONFIRMED_REACTIVE');

        $this->getJson('/api/v1/donors?filter[deferral_status]=permanent', $this->bearer($this->userOf($facility->id)))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $permanent->public_id);
    }

    public function test_ten_deferred_donors_cost_at_most_three_queries_for_their_status(): void
    {
        $facility = Facility::factory()->create();
        $donors = Donor::factory()->count(10)->create(['registered_facility_id' => $facility->id]);
        foreach ($donors as $donor) {
            $this->defer($donor, 'PREGNANT_OR_LACTATING');
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $statuses = (new SummariseDeferralStatus)->forDonorIds($donors->pluck('id')->all(), new DateTimeImmutable);

        $this->assertLessThanOrEqual(3, $queries);
        $this->assertCount(10, array_filter($statuses, fn (string $s): bool => $s === SummariseDeferralStatus::TEMPORARY));
    }

    public function test_a_page_of_ten_deferred_donors_reads_deferrals_once(): void
    {
        $facility = Facility::factory()->create();
        foreach (Donor::factory()->count(10)->create(['registered_facility_id' => $facility->id]) as $donor) {
            $this->defer($donor, 'PREGNANT_OR_LACTATING');
        }
        $headers = $this->bearer($this->userOf($facility->id));

        $deferralQueries = 0;
        DB::listen(function ($query) use (&$deferralQueries): void {
            if (str_contains($query->sql, '"deferrals"')) {
                $deferralQueries++;
            }
        });

        $response = $this->getJson('/api/v1/donors', $headers);

        $response->assertOk()->assertJsonCount(10, 'data');
        $this->assertSame(1, $deferralQueries);
    }

    public function test_staff_of_facility_b_sees_no_donor_of_facility_a(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        Donor::factory()->count(2)->create(['registered_facility_id' => $facilityA->id]);
        $own = Donor::factory()->create(['registered_facility_id' => $facilityB->id]);

        $this->getJson('/api/v1/donors', $this->bearer($this->userOf($facilityB->id)))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->public_id);
    }

    public function test_a_role_without_donor_view_gets_403(): void
    {
        $facility = Facility::factory()->create();
        Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $this->getJson('/api/v1/donors', $this->bearer($this->userOf($facility->id, 'donor')))->assertForbidden();
    }

    public function test_the_list_needs_a_token(): void
    {
        $this->getJson('/api/v1/donors')->assertUnauthorized();
    }

    public function test_an_unknown_sort_column_is_rejected(): void
    {
        $this->getJson('/api/v1/donors?sort=nik', $this->bearer($this->userOf(Facility::factory()->create()->id)))
            ->assertStatus(422)
            ->assertJsonPath('error.details.sort.0.rule', 'invalid_sort');
    }

    public function test_an_unknown_filter_is_rejected(): void
    {
        $this->getJson('/api/v1/donors?filter[phone]=x', $this->bearer($this->userOf(Facility::factory()->create()->id)))
            ->assertStatus(422)
            ->assertJsonPath('error.details.filter.0.rule', 'unsupported_filter');
    }

    public function test_a_merged_donor_record_is_not_listed(): void
    {
        $facility = Facility::factory()->create();
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $source->forceFill(['merged_into_id' => $target->id, 'merged_at' => now()])->save();

        $this->getJson('/api/v1/donors', $this->bearer($this->userOf($facility->id)))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->public_id);
    }
}
