<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\FacilityTtiPanelEntry;
use App\Models\TtiTestResult;
use App\Models\TtiTestType;
use App\Models\User;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Inventory\Application\SeparateIntoComponents;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ComponentSeparationPlan;
use App\Modules\Inventory\Domain\DerivedComponentSpec;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TtiTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class LabTestEndpointsTest extends TestCase
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
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    private function recordTti(BloodBatch $unit, User $staff, string $code, string $result, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/blood-batches/{$unit->public_id}/tti-results", [
            'test_code' => $code,
            'result' => $result,
            'tested_at' => '2026-03-02T09:00:00+00:00',
            ...$extra,
        ], $this->headers($staff));
    }

    /** Kartu 250: skrining reaktif memusnahkan unit; hasilnya tetap terbaca utuh. */
    public function test_a_reactive_screen_discards_the_unit_with_the_test_as_reason(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);

        $this->recordTti($unit, $staff, 'hiv_1_2', 'reactive')
            ->assertCreated()
            ->assertJsonPath('data.result', 'reactive');

        $this->getJson("/api/v1/blood-batches/{$unit->public_id}", $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.status', 'discarded')
            ->assertJsonPath('data.discard_reason', 'TTI screening reactive: hiv_1_2');
        $this->getJson("/api/v1/blood-batches/{$unit->public_id}/lab-results", $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.screening_verdict', 'reactive');
    }

    public function test_a_full_non_reactive_panel_still_does_not_release_the_unit(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);

        foreach (self::MANDATORY as $code) {
            $this->recordTti($unit, $staff, $code, 'non_reactive')->assertCreated();
        }

        $this->assertSame(BatchStatus::QUARANTINED, $unit->fresh()?->status);
        $this->getJson("/api/v1/blood-batches/{$unit->public_id}/lab-results", $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.screening_verdict', 'all_non_reactive')
            ->assertJsonCount(4, 'data.panel');
    }

    public function test_a_test_outside_the_facility_panel_is_409_until_the_facility_opts_in(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);

        $this->recordTti($unit, $staff, 'malaria', 'non_reactive')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAB_TEST_NOT_IN_PANEL');
        $this->assertSame(0, TtiTestResult::query()->count());

        FacilityTtiPanelEntry::query()->create([
            'facility_id' => $unit->facility_id,
            'tti_test_type_id' => TtiTestType::query()->where('code', 'malaria')->value('id'),
        ]);

        $this->recordTti($unit, $staff, 'malaria', 'non_reactive')->assertCreated();
    }

    public function test_a_second_result_for_the_same_stage_is_a_duplicate(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);

        $this->recordTti($unit, $staff, 'hcv', 'non_reactive')->assertCreated();
        $this->recordTti($unit, $staff, 'hcv', 'reactive')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAB_RESULT_DUPLICATE');

        $this->assertSame(1, TtiTestResult::query()->count());
    }

    public function test_a_confirmatory_test_needs_a_non_negative_screen_and_never_clears_it(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);

        $this->recordTti($unit, $staff, 'syphilis', 'non_reactive', ['is_confirmatory' => true])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAB_RESULT_REJECTED');

        $this->recordTti($unit, $staff, 'syphilis', 'reactive')->assertCreated();
        $this->recordTti($unit, $staff, 'syphilis', 'non_reactive', ['is_confirmatory' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_confirmatory', true);

        // Konfirmasi negatif tidak membersihkan skrining yang reaktif.
        $this->getJson("/api/v1/blood-batches/{$unit->public_id}/lab-results", $this->headers($staff))
            ->assertJsonPath('data.screening_verdict', 'reactive')
            ->assertJsonCount(2, 'data.tti_results');
    }

    public function test_the_result_row_carries_the_donation_of_its_unit(): void
    {
        $donor = Donor::factory()->create();
        $donation = $this->donationFor($donor);
        event(new DonationCompleted($donor->id, $donation->id));
        $unit = BloodBatch::query()->where('donation_id', $donation->id)->firstOrFail();

        $this->recordTti($unit, $this->userOf($unit->facility_id), 'hbsag', 'non_reactive')->assertCreated();

        $this->assertSame($donation->id, TtiTestResult::query()->sole()->donation_id);
    }

    public function test_abo_rh_is_copied_to_the_unit_and_its_components_without_touching_status(): void
    {
        $unit = $this->quarantinedUnit();
        $unit->forceFill(['blood_group' => null, 'rh_factor' => null])->save();
        $staff = $this->userOf($unit->facility_id);
        [$plasma] = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 200, -22.0),
        ]));

        $this->postJson("/api/v1/blood-batches/{$unit->public_id}/abo-rh", [
            'blood_group' => 'O', 'rh_factor' => 'negative', 'determined_at' => '2026-03-02T09:00:00+00:00',
        ], $this->headers($staff))->assertCreated();

        $unit->refresh();
        $plasma->refresh();
        $this->assertSame(['O', 'negative'], [$unit->blood_group, $unit->rh_factor]);
        $this->assertSame(['O', 'negative'], [$plasma->blood_group, $plasma->rh_factor]);
        $this->assertSame(BatchStatus::SEPARATED, $unit->status);
        $this->assertSame(BatchStatus::QUARANTINED, $plasma->status);

        $this->postJson("/api/v1/blood-batches/{$unit->public_id}/abo-rh", [
            'blood_group' => 'A', 'rh_factor' => 'positive', 'determined_at' => '2026-03-02T10:00:00+00:00',
        ], $this->headers($staff))->assertStatus(409)->assertJsonPath('error.code', 'LAB_RESULT_DUPLICATE');
    }

    public function test_results_go_on_the_source_unit_and_components_read_them_from_there(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);
        [$platelets] = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::PLATELET_CONCENTRATE, 50, 22.0),
        ]));

        $this->recordTti($platelets, $staff, 'hiv_1_2', 'non_reactive')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAB_RESULT_REJECTED');

        $this->recordTti($unit, $staff, 'hiv_1_2', 'reactive')->assertCreated();

        $this->getJson("/api/v1/blood-batches/{$platelets->public_id}/lab-results", $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.source_unit_id', $unit->public_id)
            ->assertJsonPath('data.screening_verdict', 'reactive');
    }

    public function test_a_released_unit_takes_no_new_results(): void
    {
        $unit = BloodBatch::factory()->released()->create();

        $this->recordTti($unit, $this->userOf($unit->facility_id), 'hcv', 'reactive')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LAB_RESULT_REJECTED');
    }

    // Satu user per test: dalam satu test, guard Sanctum tetap memegang user dari
    // request pertama meski token berganti.

    public function test_recording_a_result_needs_a_token(): void
    {
        $unit = $this->quarantinedUnit();

        $this->postJson("/api/v1/blood-batches/{$unit->public_id}/tti-results", [])->assertUnauthorized();
    }

    public function test_staff_of_another_facility_cannot_record_or_read_results(): void
    {
        $unit = $this->quarantinedUnit();
        $outsider = $this->userOf(Facility::factory()->create()->id);

        $this->recordTti($unit, $outsider, 'hcv', 'reactive')->assertForbidden();
        $this->getJson("/api/v1/blood-batches/{$unit->public_id}/lab-results", $this->headers($outsider))->assertForbidden();
        $this->assertSame(0, TtiTestResult::query()->count());
    }

    public function test_a_donor_of_the_same_facility_cannot_record_results(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, $this->userOf($unit->facility_id, 'donor'), 'hcv', 'reactive')->assertForbidden();
        $this->assertSame(0, TtiTestResult::query()->count());
    }

    public function test_a_result_is_one_of_three_values_not_a_boolean(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, $this->userOf($unit->facility_id), 'hcv', 'positive')
            ->assertStatus(422)
            ->assertJsonPath('error.details.result.0.rule', 'enum');
    }

    private function donationFor(Donor $donor): Donation
    {
        $appointmentId = DB::table('appointments')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'scheduled_for' => null,
            'status' => 'completed',
            'arrived_at' => now()->subHour(),
            'screened_at' => now()->subMinutes(40),
            'completed_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $donation = new Donation([
            'volume_ml' => 450,
            'started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(5),
        ]);

        $donation->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'appointment_id' => $appointmentId,
        ])->save();

        return $donation;
    }
}
