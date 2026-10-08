<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\FacilityTtiPanelEntry;
use App\Models\TtiTestType;
use App\Models\User;
use App\Modules\Inventory\Application\RecordTtiTestResult;
use App\Modules\Inventory\Application\SeparateIntoComponents;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ComponentSeparationPlan;
use App\Modules\Inventory\Domain\DerivedComponentSpec;
use App\Modules\Inventory\Domain\TtiResult;
use App\Modules\Inventory\Domain\TtiTestCode;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TtiTestTypeSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class UnitReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    private const array MANDATORY = ['hiv_1_2', 'hbsag', 'hcv', 'syphilis'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(TtiTestTypeSeeder::class);
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    private function quarantinedUnit(?int $facilityId = null): BloodBatch
    {
        return BloodBatch::factory()->create([
            'component' => 'whole_blood',
            'status' => 'quarantined',
            ...($facilityId !== null ? ['facility_id' => $facilityId] : []),
        ]);
    }

    /**
     * Hasil dicatat lewat service, bukan HTTP: satu user terautentikasi per test
     * (guard Sanctum memegang user dari request pertama meski tokennya berganti).
     */
    private function recordTti(BloodBatch $unit, string $code, string $result, bool $confirmatory = false): void
    {
        app(RecordTtiTestResult::class)->handle(
            $unit,
            TtiTestCode::from($code),
            TtiResult::from($result),
            $confirmatory,
            new DateTimeImmutable('2026-03-02T09:00:00+00:00'),
            null,
        );
    }

    /** @param  list<string>  $codes */
    private function recordNonReactive(BloodBatch $unit, array $codes = self::MANDATORY): void
    {
        foreach ($codes as $code) {
            $this->recordTti($unit, $code, 'non_reactive');
        }
    }

    /** @return TestResponse<Response> */
    private function release(BloodBatch $unit, User $user): TestResponse
    {
        return $this->postJson("/api/v1/blood-batches/{$unit->public_id}/release", [], $this->headers($user));
    }

    private function assertStillQuarantined(BloodBatch $unit): void
    {
        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::QUARANTINED, $fresh?->status);
        $this->assertNull($fresh->released_at);
    }

    public function test_a_full_non_reactive_panel_releases_the_unit(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);

        $response = $this->release($unit, $this->userOf($unit->facility_id))
            ->assertOk()
            ->assertJsonPath('data.id', $unit->public_id)
            ->assertJsonPath('data.status', 'released');

        $this->assertNotNull($response->json('data.released_at'));
        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::RELEASED, $fresh?->status);
        $this->assertNotNull($fresh->released_at);
    }

    public function test_one_reactive_screen_is_409_and_the_unit_stays_quarantined(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit, ['hiv_1_2', 'hbsag', 'syphilis']);
        $this->recordTti($unit, 'hcv', 'reactive');

        $this->release($unit, $this->userOf($unit->facility_id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $this->assertStillQuarantined($unit);
    }

    public function test_an_indeterminate_screen_is_409_not_treated_as_non_reactive(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit, ['hiv_1_2', 'hbsag', 'hcv']);
        $this->recordTti($unit, 'syphilis', 'indeterminate');

        $this->release($unit, $this->userOf($unit->facility_id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $this->assertStillQuarantined($unit);
    }

    public function test_three_of_four_mandatory_tests_is_409(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit, ['hiv_1_2', 'hbsag', 'hcv']);

        $this->release($unit, $this->userOf($unit->facility_id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $this->assertStillQuarantined($unit);
    }

    /** Jebakan utama kartu: konfirmasi non-reaktif tidak boleh menimpa skrining reaktif. */
    public function test_a_non_reactive_confirmatory_result_never_clears_a_reactive_screen(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit, ['hiv_1_2', 'hbsag', 'hcv']);
        $this->recordTti($unit, 'syphilis', 'reactive');
        $this->recordTti($unit, 'syphilis', 'non_reactive', confirmatory: true);

        $this->release($unit, $this->userOf($unit->facility_id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $this->assertStillQuarantined($unit);
    }

    public function test_a_facility_that_opted_into_malaria_needs_five_tests_not_four(): void
    {
        $unit = $this->quarantinedUnit();
        FacilityTtiPanelEntry::query()->create([
            'facility_id' => $unit->facility_id,
            'tti_test_type_id' => TtiTestType::query()->where('code', 'malaria')->value('id'),
        ]);
        $this->recordNonReactive($unit);
        $staff = $this->userOf($unit->facility_id);

        $this->release($unit, $staff)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');
        $this->assertStillQuarantined($unit);

        $this->recordTti($unit, 'malaria', 'non_reactive');

        $this->release($unit, $staff)
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
    }

    public function test_a_derived_component_is_released_from_its_parents_clean_panel(): void
    {
        $unit = $this->quarantinedUnit();
        [$plasma] = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 200, -22.0),
        ]));
        $this->recordNonReactive($unit);

        $this->release($plasma, $this->userOf($unit->facility_id))
            ->assertOk()
            ->assertJsonPath('data.id', $plasma->public_id)
            ->assertJsonPath('data.parent_id', $unit->public_id)
            ->assertJsonPath('data.status', 'released');

        $this->assertSame(BatchStatus::RELEASED, $plasma->fresh()?->status);
        $this->assertSame(BatchStatus::SEPARATED, $unit->fresh()?->status);
    }

    public function test_a_derived_component_is_rejected_when_its_parents_panel_is_reactive(): void
    {
        $unit = $this->quarantinedUnit();
        [$plasma] = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 200, -22.0),
        ]));
        $this->recordNonReactive($unit, ['hiv_1_2', 'hbsag', 'syphilis']);
        $this->recordTti($unit, 'hcv', 'reactive');

        $this->release($plasma, $this->userOf($unit->facility_id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $this->assertStillQuarantined($plasma);
    }

    public function test_a_separated_parent_can_never_be_released(): void
    {
        $unit = $this->quarantinedUnit();
        app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 200, -22.0),
        ]));
        $this->recordNonReactive($unit);

        $this->release($unit, $this->userOf($unit->facility_id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::SEPARATED, $fresh?->status);
        $this->assertNull($fresh->released_at);
    }

    public function test_an_already_released_unit_cannot_be_released_again(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);
        $staff = $this->userOf($unit->facility_id);

        $this->release($unit, $staff)->assertOk();
        $releasedAt = $unit->fresh()?->released_at;

        $this->release($unit, $staff)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');
        $this->assertEquals($releasedAt, $unit->fresh()?->released_at);
    }

    public function test_a_status_update_still_cannot_release_a_unit_with_a_clean_panel(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);
        // TESTING, bukan QUARANTINED: hanya dari sini graf mengizinkan RELEASED, jadi
        // satu-satunya yang menolak adalah penjaga releaseIsGated() kartu 230.
        $unit->forceFill(['status' => BatchStatus::TESTING])->save();

        $this->patchJson(
            "/api/v1/blood-batches/{$unit->public_id}/status",
            ['status' => 'released'],
            $this->headers($this->userOf($unit->facility_id)),
        )->assertStatus(409)->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');

        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::TESTING, $fresh?->status);
        $this->assertNull($fresh->released_at);
    }

    public function test_staff_of_another_facility_is_403(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);
        $other = $this->quarantinedUnit();

        $this->release($unit, $this->userOf($other->facility_id))->assertStatus(403);

        $this->assertStillQuarantined($unit);
    }

    public function test_a_donor_account_is_403(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);

        $this->release($unit, $this->userOf($unit->facility_id, 'donor'))->assertStatus(403);

        $this->assertStillQuarantined($unit);
    }

    public function test_release_requires_a_token(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);

        $this->postJson("/api/v1/blood-batches/{$unit->public_id}/release", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(401);

        $this->assertStillQuarantined($unit);
    }

    public function test_the_database_rejects_a_released_status_without_released_at(): void
    {
        $unit = $this->quarantinedUnit();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_released_shape');

        DB::table('blood_batches')->where('id', $unit->id)->update(['status' => 'released']);
    }
}
