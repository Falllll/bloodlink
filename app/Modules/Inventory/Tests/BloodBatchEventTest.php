<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\AuditLog;
use App\Models\BloodBatch;
use App\Models\BloodBatchEvent;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Inventory\Application\RecordTtiTestResult;
use App\Modules\Inventory\Application\SeparateIntoComponents;
use App\Modules\Inventory\Application\TransitionBloodBatch;
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

/**
 * Satu user terautentikasi per test: guard Sanctum memegang user dari request
 * pertama meski tokennya berganti.
 */
final class BloodBatchEventTest extends TestCase
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
            // Rencana pemisahan di bawah butuh 400 ml.
            'volume_ml' => 450,
            ...($facilityId !== null ? ['facility_id' => $facilityId] : []),
        ]);
    }

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

    /** @return list<array{0: ?string, 1: string}> */
    private function transitionsOf(BloodBatch $unit): array
    {
        return BloodBatchEvent::query()
            ->where('blood_batch_id', $unit->id)
            ->orderBy('id')
            ->get()
            ->map(fn (BloodBatchEvent $event): array => [$event->from_status?->value, $event->to_status->value])
            ->all();
    }

    public function test_registering_a_unit_records_a_birth_event_with_a_null_from_status(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userOf($facility->id);

        $response = $this->postJson('/api/v1/blood-batches', [
            'component' => 'fresh_frozen_plasma',
            'blood_group' => 'A',
            'rh_factor' => 'positive',
            'volume_ml' => 250,
            'collected_at' => '2026-03-15T08:00:00+00:00',
            'storage_temperature_c' => -30.0,
        ], $this->headers($staff))->assertCreated();

        $unit = BloodBatch::query()->where('public_id', $response->json('data.id'))->sole();

        $this->assertSame([[null, 'quarantined']], $this->transitionsOf($unit));
    }

    public function test_a_unit_created_without_a_status_records_the_database_default(): void
    {
        // BloodBatchFactory tidak mengisi status: nilainya datang dari default kolom.
        $unit = BloodBatch::factory()->create();

        $this->assertSame([[null, 'quarantined']], $this->transitionsOf($unit));
    }

    public function test_updating_the_same_instance_after_a_status_less_create_records_the_real_origin(): void
    {
        // Instance yang sama, tanpa fresh(): model harus tahu status kelahirannya.
        $unit = BloodBatch::factory()->create();

        app(TransitionBloodBatch::class)->handle($unit, BatchStatus::TESTING);

        $this->assertSame([[null, 'quarantined'], ['quarantined', 'testing']], $this->transitionsOf($unit));
    }

    public function test_a_transition_records_one_event_with_both_statuses(): void
    {
        $unit = $this->quarantinedUnit();

        app(TransitionBloodBatch::class)->handle($unit, BatchStatus::TESTING);

        $this->assertSame([[null, 'quarantined'], ['quarantined', 'testing']], $this->transitionsOf($unit));
    }

    public function test_a_discard_records_its_reason(): void
    {
        $unit = $this->quarantinedUnit();

        app(TransitionBloodBatch::class)->handle($unit, BatchStatus::DISCARDED, 'Kantong bocor.');

        $event = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->where('to_status', 'discarded')->sole();
        $this->assertSame(BatchStatus::QUARANTINED, $event->from_status);
        $this->assertSame('Kantong bocor.', $event->reason);
    }

    public function test_a_non_status_update_records_nothing(): void
    {
        $unit = $this->quarantinedUnit();
        $events = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->count();
        $audits = AuditLog::query()->where('auditable_type', BloodBatch::class)->where('auditable_id', $unit->id)->count();

        $unit->forceFill(['volume_ml' => 430])->save();

        $this->assertSame($events, BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->count());
        // Dua tabel, dua tugas: audit_logs tetap mencatat perubahan kolomnya.
        $this->assertSame($audits + 1, AuditLog::query()->where('auditable_type', BloodBatch::class)->where('auditable_id', $unit->id)->count());
    }

    public function test_the_release_gate_also_records_an_event(): void
    {
        $unit = $this->quarantinedUnit();
        $this->recordNonReactive($unit);

        $this->release($unit, $this->userOf($unit->facility_id))->assertOk();

        // Gerbang menempuh testing di memori dalam satu save: yang tersimpan adalah quarantined -> released.
        $this->assertSame([[null, 'quarantined'], ['quarantined', 'released']], $this->transitionsOf($unit));
    }

    public function test_a_reactive_tti_discard_also_records_an_event(): void
    {
        $unit = $this->quarantinedUnit();

        $this->recordTti($unit, 'hcv', 'reactive');

        $event = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->where('to_status', 'discarded')->sole();
        $this->assertSame(BatchStatus::QUARANTINED, $event->from_status);
        $this->assertSame('TTI screening reactive: hcv', $event->reason);
    }

    public function test_component_separation_records_events_for_parent_and_children(): void
    {
        $unit = $this->quarantinedUnit();

        $children = app(SeparateIntoComponents::class)->handle($unit, new ComponentSeparationPlan([
            new DerivedComponentSpec(BloodComponent::FRESH_FROZEN_PLASMA, 200, -22.0),
            new DerivedComponentSpec(BloodComponent::PACKED_RED_CELLS, 200, 4.0),
        ]));

        $this->assertSame([[null, 'quarantined'], ['quarantined', 'separated']], $this->transitionsOf($unit));
        foreach ($children as $child) {
            $this->assertSame([[null, 'quarantined']], $this->transitionsOf($child));
        }
    }

    public function test_the_event_carries_the_units_facility_not_the_actors(): void
    {
        $facility = Facility::factory()->create();
        $otherFacility = Facility::factory()->create();
        $unit = $this->quarantinedUnit($facility->id);
        $actor = $this->userOf($otherFacility->id, 'admin');

        $this->actingAs($actor);
        app(TransitionBloodBatch::class)->handle($unit, BatchStatus::TESTING);

        $event = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->where('to_status', 'testing')->sole();
        $this->assertSame($facility->id, $event->facility_id);
        $this->assertSame($otherFacility->id, $event->actor_facility_id);
        $this->assertNotSame($event->facility_id, $event->actor_facility_id);
    }

    public function test_the_event_carries_the_units_public_id(): void
    {
        $unit = $this->quarantinedUnit();

        app(TransitionBloodBatch::class)->handle($unit, BatchStatus::TESTING);

        $event = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->where('to_status', 'testing')->sole();
        $this->assertSame($unit->public_id, $event->batch_public_id);
        $this->assertSame([
            'batch_public_id' => $unit->public_id,
            'from_status' => 'quarantined',
            'to_status' => 'testing',
            'reason' => null,
            'occurred_at' => $event->occurred_at->toAtomString(),
        ], $event->toApiArray());
    }

    public function test_the_actor_is_the_authenticated_user(): void
    {
        $unit = $this->quarantinedUnit();
        $staff = $this->userOf($unit->facility_id);

        $this->patchJson("/api/v1/blood-batches/{$unit->public_id}/status", ['status' => 'testing'], $this->headers($staff))
            ->assertOk();

        $event = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->where('to_status', 'testing')->sole();
        $this->assertSame($staff->id, $event->actor_id);
        $this->assertSame($staff->facility_id, $event->actor_facility_id);
        $this->assertNotNull($event->trace_id);
    }

    public function test_a_units_history_is_queryable_by_public_id_and_to_status(): void
    {
        $discarded = $this->quarantinedUnit();
        $kept = $this->quarantinedUnit($discarded->facility_id);
        app(TransitionBloodBatch::class)->handle($discarded, BatchStatus::DISCARDED, 'Hemolisis.');
        app(TransitionBloodBatch::class)->handle($kept, BatchStatus::TESTING);

        // Pertanyaan regulator: unit mana yang dimusnahkan di fasilitas ini, dan kenapa -- tanpa mengurai JSON.
        $rows = DB::table('blood_batch_events')
            ->where('facility_id', $discarded->facility_id)
            ->where('to_status', 'discarded')
            ->whereBetween('occurred_at', [now()->subDay(), now()->addDay()])
            ->get(['batch_public_id', 'reason']);

        $this->assertCount(1, $rows);
        $this->assertSame($discarded->public_id, $rows->first()->batch_public_id);
        $this->assertSame('Hemolisis.', $rows->first()->reason);

        $this->assertSame(
            ['quarantined', 'discarded'],
            BloodBatchEvent::query()->where('batch_public_id', $discarded->public_id)->orderBy('id')->get()
                ->map(fn (BloodBatchEvent $event): string => $event->to_status->value)->all(),
        );
    }

    public function test_rows_can_never_be_updated(): void
    {
        $unit = $this->quarantinedUnit();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batch_events is append-only');

        DB::table('blood_batch_events')->where('blood_batch_id', $unit->id)->update(['reason' => 'x']);
    }

    public function test_rows_can_never_be_deleted(): void
    {
        $unit = $this->quarantinedUnit();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batch_events is append-only');

        DB::table('blood_batch_events')->where('blood_batch_id', $unit->id)->delete();
    }

    public function test_an_event_must_be_a_change(): void
    {
        $unit = $this->quarantinedUnit();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_batch_events_change_shape');

        DB::table('blood_batch_events')->insert([
            'blood_batch_id' => $unit->id,
            'batch_public_id' => $unit->public_id,
            'facility_id' => $unit->facility_id,
            'from_status' => 'quarantined',
            'to_status' => 'quarantined',
            'occurred_at' => now(),
        ]);
    }

    /** Batas yang dinyatakan terbuka (lihat BloodBatchEventRecorder): jalur mentah tidak terekam. */
    public function test_a_raw_db_update_bypasses_the_recorder(): void
    {
        $unit = $this->quarantinedUnit();
        $before = BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->count();

        DB::table('blood_batches')->where('id', $unit->id)->update(['status' => 'expired']);

        $this->assertSame(BatchStatus::EXPIRED, $unit->fresh()?->status);
        $this->assertSame($before, BloodBatchEvent::query()->where('blood_batch_id', $unit->id)->count());
    }
}
