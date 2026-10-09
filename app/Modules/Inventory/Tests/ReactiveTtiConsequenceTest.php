<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\User;
use App\Modules\Donor\Domain\Events\DonorPermanentlyDeferred;
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
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TtiTestTypeSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Rujukan Pedoman Medis §11.3: skrining reaktif menjatuhkan unit, konfirmasi
 * reaktif menjatuhkan donor -- dua akibat terpisah, dua pemicu terpisah.
 */
final class ReactiveTtiConsequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(TtiTestTypeSeeder::class);
        $this->seed(DeferralReasonSeeder::class);
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

    private function quarantinedUnit(): BloodBatch
    {
        // Volume tetap: rencana pemisahan di bawah butuh 400 ml.
        return BloodBatch::factory()->create(['component' => 'whole_blood', 'status' => 'quarantined', 'volume_ml' => 450]);
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

    /** Selalu dibaca ulang dari DB: akibatnya tidak boleh hanya hidup di model yang dipegang test. */
    private function reload(BloodBatch $unit): BloodBatch
    {
        return BloodBatch::query()->findOrFail($unit->getKey());
    }

    /** @return list<DerivedComponentSpec> */
    private function plasmaAndRedCells(): array
    {
        return [
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 200, -22.0),
            new DerivedComponentSpec(BloodComponent::PACKED_RED_CELLS, 200, 4.0),
        ];
    }

    private function permanentDeferralsOf(BloodBatch $unit): int
    {
        return DB::table('deferrals')->where('donor_id', $unit->donor_id)->where('type', 'permanent')->count();
    }

    public function test_a_reactive_screen_discards_the_unit_with_a_reason(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'reactive');

        $fresh = $this->reload($unit);
        $this->assertSame(BatchStatus::DISCARDED, $fresh->status);
        $this->assertSame('TTI screening reactive: hcv', $fresh->discard_reason);
    }

    public function test_a_reactive_screen_discards_the_derived_components_too(): void
    {
        $unit = $this->quarantinedUnit();
        $children = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan($this->plasmaAndRedCells()));

        $this->recordTti($unit, 'hiv_1_2', 'reactive');

        // Induk separated terminal menurut graf: dilewati, bukan error.
        $this->assertSame(BatchStatus::SEPARATED, $this->reload($unit)->status);
        foreach ($children as $child) {
            $fresh = $this->reload($child);
            $this->assertSame(BatchStatus::DISCARDED, $fresh->status);
            $this->assertSame('TTI screening reactive: hiv_1_2', $fresh->discard_reason);
        }
    }

    public function test_a_reactive_screen_alone_does_not_defer_the_donor(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'reactive');

        // Tanpa Event::fake: listener sungguhan jalan, dan tetap tidak ada deferral.
        $this->assertSame(0, DB::table('deferrals')->where('donor_id', $unit->donor_id)->count());
    }

    public function test_a_reactive_confirmation_emits_donor_permanently_deferred(): void
    {
        Event::fake([DonorPermanentlyDeferred::class]);
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'reactive');
        Event::assertNotDispatched(DonorPermanentlyDeferred::class);

        $this->recordTti($unit, 'hcv', 'reactive', confirmatory: true);

        Event::assertDispatchedTimes(DonorPermanentlyDeferred::class, 1);
        Event::assertDispatched(
            DonorPermanentlyDeferred::class,
            fn (DonorPermanentlyDeferred $event): bool => $event->donorId === $unit->donor_id
                && $event->reasonCode === 'TTI_CONFIRMED_REACTIVE'
                && $event->occurredAt === '2026-03-02T09:00:00+00:00',
        );
    }

    public function test_a_reactive_confirmation_places_exactly_one_permanent_deferral(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'reactive');
        $this->assertSame(0, $this->permanentDeferralsOf($unit));

        $this->recordTti($unit, 'hcv', 'reactive', confirmatory: true);
        $this->assertSame(1, $this->permanentDeferralsOf($unit));
    }

    public function test_two_confirmed_tests_still_place_only_one_permanent_deferral(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hiv_1_2', 'reactive');
        $this->recordTti($unit, 'hcv', 'reactive');
        $this->recordTti($unit, 'hiv_1_2', 'reactive', confirmatory: true);
        $this->recordTti($unit, 'hcv', 'reactive', confirmatory: true);

        $this->assertSame(1, DB::table('deferrals')->where('donor_id', $unit->donor_id)->count());
    }

    public function test_a_non_reactive_screen_discards_nothing(): void
    {
        Event::fake([DonorPermanentlyDeferred::class]);
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'non_reactive');

        $this->assertSame(BatchStatus::QUARANTINED, $this->reload($unit)->status);
        Event::assertNotDispatched(DonorPermanentlyDeferred::class);
    }

    public function test_an_indeterminate_screen_discards_nothing(): void
    {
        Event::fake([DonorPermanentlyDeferred::class]);
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'indeterminate');
        $this->recordTti($unit, 'hcv', 'indeterminate', confirmatory: true);

        // Indeterminate menunggu kategori simpannya sendiri (Kartu 270), bukan dimusnahkan.
        $fresh = $this->reload($unit);
        $this->assertSame(BatchStatus::QUARANTINED, $fresh->status);
        $this->assertNull($fresh->discard_reason);
        Event::assertNotDispatched(DonorPermanentlyDeferred::class);
    }

    public function test_a_second_reactive_screen_on_a_discarded_unit_does_not_throw(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordTti($unit, 'hcv', 'reactive');

        // Model segar dari DB, seperti route binding: statusnya sudah discarded.
        $this->recordTti($this->reload($unit), 'hbsag', 'reactive');

        $fresh = $this->reload($unit);
        $this->assertSame(BatchStatus::DISCARDED, $fresh->status);
        // Alasan pertama tidak ditimpa: unit yang sudah musnah dilewati saringan graf.
        $this->assertSame('TTI screening reactive: hcv', $fresh->discard_reason);
        $this->assertSame(2, DB::table('tti_test_results')->where('blood_batch_id', $unit->id)->count());
    }

    public function test_an_already_issued_child_is_left_alone_for_look_back(): void
    {
        $unit = $this->quarantinedUnit();
        [$issued, $stocked] = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan($this->plasmaAndRedCells()));
        DB::table('blood_batches')->where('id', $issued->id)->update(['status' => 'issued', 'released_at' => now()]);

        $this->recordTti($unit, 'hcv', 'reactive');

        $fresh = $this->reload($issued);
        $this->assertSame(BatchStatus::ISSUED, $fresh->status);
        $this->assertNull($fresh->discard_reason);
        $this->assertSame(BatchStatus::DISCARDED, $this->reload($stocked)->status);
    }

    public function test_a_discarded_unit_can_never_be_released(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordTti($unit, 'hcv', 'reactive');

        $this->postJson("/api/v1/blood-batches/{$unit->public_id}/release", [], $this->headers($this->userOf($unit->facility_id)))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $fresh = $this->reload($unit);
        $this->assertSame(BatchStatus::DISCARDED, $fresh->status);
        $this->assertNull($fresh->released_at);
    }

    public function test_a_unit_without_a_donor_record_emits_no_event(): void
    {
        Event::fake([DonorPermanentlyDeferred::class]);
        $unit = BloodBatch::factory()->create(['component' => 'whole_blood', 'status' => 'quarantined', 'donor_id' => null]);

        $this->recordTti($unit, 'hcv', 'reactive');
        $this->recordTti($unit, 'hcv', 'reactive', confirmatory: true);

        $this->assertSame(BatchStatus::DISCARDED, $this->reload($unit)->status);
        Event::assertNotDispatched(DonorPermanentlyDeferred::class);
    }
}
