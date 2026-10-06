<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Inventory\Application\AssignStorageProfile;
use App\Modules\Inventory\Application\Exceptions\ComponentSeparationRejected;
use App\Modules\Inventory\Application\SeparateIntoComponents;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\BloodComponent;
use App\Modules\Inventory\Domain\ComponentExpiryPolicy;
use App\Modules\Inventory\Domain\ComponentSeparationPlan;
use App\Modules\Inventory\Domain\DerivedComponentSpec;
use App\Modules\Inventory\Domain\UnitNumberFormat;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SeparateIntoComponentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
    }

    /** @param  array<string, mixed>  $overrides */
    private function wholeBlood(array $overrides = []): BloodBatch
    {
        return BloodBatch::factory()->create([
            'component' => 'whole_blood',
            'status' => 'quarantined',
            'volume_ml' => 450,
            'collected_at' => '2026-03-01 08:00:00',
            ...$overrides,
        ]);
    }

    /** PRC 250 ml di +4 °C, FFP 150 ml di -22 °C, trombosit 50 ml di +22 °C = 450 ml. */
    private function standardPlan(): ComponentSeparationPlan
    {
        return new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::PACKED_RED_CELLS, 250, 4.0),
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 150, -22.0),
            new DerivedComponentSpec(BloodComponent::PLATELET_CONCENTRATE, 50, 22.0),
        ]);
    }

    private function staffOf(int $facilityId): User
    {
        $staff = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $staff->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $staff;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ];
    }

    public function test_each_component_gets_its_own_expiry_counted_from_the_original_collection(): void
    {
        $parent = $this->wholeBlood();
        $collectedAt = new DateTimeImmutable('2026-03-01 08:00:00');

        $children = app(SeparateIntoComponents::class)->handle($parent, $this->standardPlan());

        $this->assertCount(3, $children);
        [$prc, $ffp, $platelets] = $children;

        // Trombosit 5 hari dari pengambilan ASLI -- bukan 35 hari milik induk,
        // bukan 5 hari dari saat pemisahan.
        $this->assertEquals($collectedAt->modify('+5 days'), $platelets->expires_at);
        $this->assertTrue($platelets->expires_at->lessThan($parent->expires_at));
        $this->assertEquals($collectedAt->modify('+35 days'), $prc->expires_at);
        $this->assertEquals(new DateTimeImmutable('2026-06-01 08:00:00'), $ffp->expires_at);

        foreach ($children as $child) {
            $this->assertSame($parent->id, $child->parent_unit_id);
            $this->assertNull($child->donation_id);
            $this->assertSame(BatchStatus::QUARANTINED, $child->status);
            $this->assertEquals($collectedAt, $child->collected_at);
            $this->assertNotNull($child->storage_profile_id);
            $this->assertTrue(UnitNumberFormat::isValid($child->batch_number), $child->batch_number);
        }

        $parent->refresh();
        $this->assertSame(BatchStatus::SEPARATED, $parent->status);
        $this->assertSame(450, $parent->separated_volume_ml);
        $this->assertNotNull($parent->separated_at);
    }

    public function test_the_endpoint_separates_and_returns_parent_and_components(): void
    {
        $facility = Facility::factory()->create();
        $parent = $this->wholeBlood(['facility_id' => $facility->id]);

        $response = $this->postJson("/api/v1/blood-batches/{$parent->public_id}/components", [
            'components' => [
                ['component' => 'packed_red_cells', 'volume_ml' => 250, 'storage_temperature_c' => 4],
                ['component' => 'platelet_concentrate', 'volume_ml' => 50, 'storage_temperature_c' => 22],
            ],
        ], $this->headers($this->staffOf($facility->id)));

        $response->assertCreated()
            ->assertJsonPath('data.parent.status', 'separated')
            ->assertJsonCount(2, 'data.components')
            ->assertJsonPath('data.components.0.parent_id', $parent->public_id)
            ->assertJsonPath('data.components.1.expires_at', '2026-03-06T08:00:00+00:00');
    }

    public function test_a_second_separation_of_the_same_parent_is_409_and_adds_nothing(): void
    {
        $facility = Facility::factory()->create();
        $parent = $this->wholeBlood(['facility_id' => $facility->id]);
        $staff = $this->staffOf($facility->id);
        $body = ['components' => [['component' => 'packed_red_cells', 'volume_ml' => 250, 'storage_temperature_c' => 4]]];

        $this->postJson("/api/v1/blood-batches/{$parent->public_id}/components", $body, $this->headers($staff))->assertCreated();
        $rows = BloodBatch::query()->count();

        // Masih muat secara volume (250 + 50 <= 450): yang harus menolak adalah
        // status induk yang sudah separated, bukan cek volume.
        $small = ['components' => [['component' => 'platelet_concentrate', 'volume_ml' => 50, 'storage_temperature_c' => 22]]];

        $this->postJson("/api/v1/blood-batches/{$parent->public_id}/components", $small, $this->headers($staff))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_SEPARATION_REJECTED');
        $this->assertSame($rows, BloodBatch::query()->count());
    }

    public function test_components_cannot_exceed_the_parent_volume(): void
    {
        $parent = $this->wholeBlood(['volume_ml' => 300]);

        try {
            app(SeparateIntoComponents::class)->handle($parent, $this->standardPlan()); // 450 > 300
            $this->fail('Expected ComponentSeparationRejected.');
        } catch (ComponentSeparationRejected) {
        }

        $this->assertSame(0, $parent->children()->count());
        $this->assertSame(BatchStatus::QUARANTINED, $parent->fresh()?->status);
    }

    public function test_only_an_underived_whole_blood_unit_can_be_separated(): void
    {
        $plasma = BloodBatch::factory()->create(['component' => 'fresh_frozen_plasma', 'status' => 'quarantined']);
        $plan = new ComponentSeparationPlan([new DerivedComponentSpec(BloodComponent::CRYOPRECIPITATE, 20, -22.0)]);

        $this->expectException(ComponentSeparationRejected::class);

        app(SeparateIntoComponents::class)->handle($plasma, $plan);
    }

    public function test_a_failing_expiry_on_the_second_child_rolls_everything_back(): void
    {
        $parent = $this->wholeBlood();

        $this->app->instance(ComponentExpiryPolicy::class, new class(new AssignStorageProfile) implements ComponentExpiryPolicy
        {
            private int $calls = 0;

            public function __construct(private ComponentExpiryPolicy $inner) {}

            public function resolve(string $component, float $storageTemperatureC, DateTimeImmutable $collectedAt): array
            {
                if (++$this->calls === 2) {
                    throw new RuntimeException('Penghitung kedaluwarsa gagal di anak kedua.');
                }

                return $this->inner->resolve($component, $storageTemperatureC, $collectedAt);
            }
        });

        try {
            app(SeparateIntoComponents::class)->handle($parent, $this->standardPlan());
            $this->fail('Expected the expiry policy to throw.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, BloodBatch::query()->whereNotNull('parent_unit_id')->count());
        $parent->refresh();
        $this->assertSame(BatchStatus::QUARANTINED, $parent->status);
        $this->assertSame(0, $parent->separated_volume_ml);
    }

    public function test_the_database_rejects_a_separated_volume_above_the_unit_volume(): void
    {
        $parent = $this->wholeBlood();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_separated_volume');

        DB::update('UPDATE blood_batches SET separated_volume_ml = volume_ml + 1 WHERE id = ?', [$parent->id]);
    }

    public function test_the_database_rejects_separated_without_a_timestamp(): void
    {
        $parent = $this->wholeBlood();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_separated_shape');

        DB::update("UPDATE blood_batches SET status = 'separated' WHERE id = ?", [$parent->id]);
    }

    public function test_the_database_rejects_a_derived_unit_carrying_a_donation(): void
    {
        $parent = $this->wholeBlood();
        [$child] = app(SeparateIntoComponents::class)->handle($parent, $this->standardPlan());
        $donationId = $this->donationFor(Donor::factory()->create())->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batches_derived_shape');

        DB::update('UPDATE blood_batches SET donation_id = ? WHERE id = ?', [$donationId, $child->id]);
    }

    public function test_a_plain_status_update_cannot_mark_a_unit_separated(): void
    {
        $facility = Facility::factory()->create();
        $parent = $this->wholeBlood(['facility_id' => $facility->id]);

        $this->patchJson("/api/v1/blood-batches/{$parent->public_id}/status", ['status' => 'separated'], $this->headers($this->staffOf($facility->id)))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');
        $this->assertSame(BatchStatus::QUARANTINED, $parent->fresh()?->status);
    }

    public function test_staff_of_another_facility_cannot_separate_and_whole_blood_is_not_a_component(): void
    {
        $parent = $this->wholeBlood();
        $outsider = $this->staffOf(Facility::factory()->create()->id);
        $insider = $this->staffOf($parent->facility_id);

        $this->postJson("/api/v1/blood-batches/{$parent->public_id}/components", [
            'components' => [['component' => 'packed_red_cells', 'volume_ml' => 250, 'storage_temperature_c' => 4]],
        ], $this->headers($outsider))->assertForbidden();

        $this->postJson("/api/v1/blood-batches/{$parent->public_id}/components", [
            'components' => [['component' => 'whole_blood', 'volume_ml' => 250, 'storage_temperature_c' => 4]],
        ], $this->headers($insider))
            ->assertStatus(422)
            ->assertJsonPath('error.details', fn (array $details): bool => array_key_exists('components.0.component', $details));
    }

    public function test_look_back_works_in_both_directions(): void
    {
        $donor = Donor::factory()->create();
        $donation = $this->donationFor($donor);
        event(new DonationCompleted($donor->id, $donation->id));
        $parent = BloodBatch::query()->where('donation_id', $donation->id)->firstOrFail();

        $children = app(SeparateIntoComponents::class)->handle($parent, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::PACKED_RED_CELLS, 250, 4.0),
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 150, -35.0),
        ]));

        // Donor -> semua kantong turunannya.
        $fromDonor = BloodBatch::query()->where('donor_id', $donor->id)->whereNotNull('parent_unit_id')->pluck('id')->sort()->values()->all();
        $this->assertSame(collect($children)->pluck('id')->sort()->values()->all(), $fromDonor);

        // Turunan -> induk -> donasi -> donor.
        $traced = BloodBatch::query()->findOrFail($children[1]->id)->parent?->donation?->donor;
        $this->assertNotNull($traced);
        $this->assertSame($donor->id, $traced->id);
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
