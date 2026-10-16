<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\Appointment;
use App\Models\BloodBatch;
use App\Models\BloodRequestAllocation;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\Transfusion;
use App\Models\TransfusionReaction;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\Exceptions\TransfusionRejected;
use App\Modules\Request\Application\IssueAllocatedUnit;
use App\Modules\Request\Application\RecordCrossmatch;
use App\Modules\Request\Application\RecordTransfusion;
use App\Modules\Request\Application\ReportTransfusionReaction;
use App\Modules\Request\Application\ReserveAllocatedUnits;
use App\Modules\Request\Domain\CrossmatchResult;
use App\Modules\Request\Domain\Events\TransfusionReactionReported;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TransfusionReactionTypeSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Hemovigilans (kartu 370). Satu user terautentikasi per test; langkah peran lain
 * (alokasi, reservasi, crossmatch, penerbitan) lewat service.
 */
final class TransfusionTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);
        $this->seed(TransfusionReactionTypeSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    private function staff(?Facility $facility = null): User
    {
        $facility ??= $this->facility;
        $user = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $user->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function withKey(array $headers): array
    {
        return $headers + ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** Alokasi O- yang sudah dipesan; (default) sudah lolos crossmatch dan diterbitkan. */
    private function allocation(bool $issue = true): BloodRequestAllocation
    {
        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells', 'facility_id' => $this->facility->id]);
        $unit->forceFill([
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'collected_at' => now()->subDays(30),
            'expires_at' => now()->addDays(10),
            'status' => 'released',
            'released_at' => now()->subDays(29),
        ])->save();

        $request = app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            1,
            RequestUrgency::URGENT,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );

        [$allocation] = app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);
        app(ReserveAllocatedUnits::class)->handle($request);

        if ($issue) {
            app(RecordCrossmatch::class)->handle($allocation, CrossmatchResult::COMPATIBLE, User::factory()->create());
            app(IssueAllocatedUnit::class)->handle($allocation, User::factory()->create());
        }

        return $allocation->fresh() ?? $allocation;
    }

    private function transfusion(?BloodRequestAllocation $allocation = null): Transfusion
    {
        return app(RecordTransfusion::class)->handle(
            $allocation ?? $this->allocation(),
            User::factory()->create(),
            now()->subHours(3)->toDateTimeImmutable(),
            now()->subHours(2)->toDateTimeImmutable(),
            300,
        );
    }

    private function unitStatus(BloodRequestAllocation $allocation): string
    {
        return (string) DB::table('blood_batches')->where('id', $allocation->blood_batch_id)->value('status');
    }

    public function test_a_transfusion_is_recorded_for_an_issued_unit_which_stays_issued(): void
    {
        $allocation = $this->allocation();

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", [
            'started_at' => now()->subHour()->toIso8601String(),
            'completed_at' => now()->toIso8601String(),
            'volume_transfused_ml' => 280,
        ], $this->withKey($this->headers($this->staff())))
            ->assertCreated()
            ->assertJsonPath('data.allocation_id', $allocation->public_id)
            ->assertJsonPath('data.volume_transfused_ml', 280)
            ->assertJsonPath('data.reactions', [])
            ->assertJsonMissingPath('data.recorded_by');

        $this->assertSame(1, Transfusion::query()->where('blood_request_allocation_id', $allocation->id)->count());
        $this->assertSame('issued', $this->unitStatus($allocation));
    }

    public function test_an_ongoing_transfusion_has_no_completion_and_no_volume(): void
    {
        $allocation = $this->allocation();

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", [
            'started_at' => now()->toIso8601String(),
        ], $this->withKey($this->headers($this->staff())))
            ->assertCreated()
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.volume_transfused_ml', null);
    }

    public function test_a_second_transfusion_for_the_same_allocation_is_409(): void
    {
        $allocation = $this->allocation();
        $this->transfusion($allocation);

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", [
            'started_at' => now()->toIso8601String(),
        ], $this->withKey($this->headers($this->staff())))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TRANSFUSION_REJECTED');

        $this->assertSame(1, Transfusion::query()->where('blood_request_allocation_id', $allocation->id)->count());
    }

    public function test_a_unit_that_is_not_issued_cannot_have_been_transfused(): void
    {
        $allocation = $this->allocation(issue: false);
        $this->assertSame('reserved', $this->unitStatus($allocation));

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", [
            'started_at' => now()->toIso8601String(),
        ], $this->withKey($this->headers($this->staff())))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TRANSFUSION_REJECTED');

        $this->assertSame(0, Transfusion::query()->count());
    }

    public function test_the_service_rejects_an_unissued_unit_too(): void
    {
        $this->expectException(TransfusionRejected::class);

        $this->transfusion($this->allocation(issue: false));
    }

    public function test_one_transfusion_holds_several_reactions_read_back_in_observed_order(): void
    {
        $transfusion = $this->transfusion();
        $service = app(ReportTransfusionReaction::class);

        // Dilaporkan terbalik: reaksi tertunda lebih dulu dicatat, yang segera menyusul.
        $service->handle($transfusion, User::factory()->create(), now()->subMinutes(30)->toDateTimeImmutable(), 'Ikterus, Hb turun.');
        $service->handle($transfusion, User::factory()->create(), now()->subHours(2)->addMinutes(10)->toDateTimeImmutable(), 'Demam dan menggigil saat transfusi.');

        $this->getJson("/api/v1/transfusions/{$transfusion->public_id}", $this->headers($this->staff()))
            ->assertOk()
            ->assertJsonCount(2, 'data.reactions')
            ->assertJsonPath('data.reactions.0.description', 'Demam dan menggigil saat transfusi.')
            ->assertJsonPath('data.reactions.1.description', 'Ikterus, Hb turun.')
            ->assertJsonPath('data.reactions.0.reaction_type_code', null)
            ->assertJsonMissingPath('data.reactions.0.reported_by');
    }

    public function test_observed_at_is_kept_apart_from_the_report_time(): void
    {
        $transfusion = $this->transfusion();
        $observedAt = now()->subHours(6)->toIso8601String();

        $this->postJson("/api/v1/transfusions/{$transfusion->public_id}/reactions", [
            'observed_at' => $observedAt,
            'description' => 'Urtikaria.',
        ], $this->withKey($this->headers($this->staff())))
            ->assertCreated()
            ->assertJsonPath('data.observed_at', $observedAt)
            ->assertJsonPath('data.reported_at', now()->toIso8601String());
    }

    public function test_a_reaction_changes_no_status_and_emits_an_event_without_patient_data(): void
    {
        $allocation = $this->allocation();
        $transfusion = $this->transfusion($allocation);
        $requestStatus = (string) DB::table('blood_requests')->where('id', $allocation->blood_request_id)->value('status');
        Event::fake([TransfusionReactionReported::class]);

        $reaction = app(ReportTransfusionReaction::class)->handle($transfusion, User::factory()->create(), now()->subHour()->toDateTimeImmutable(), 'Sesak napas.');

        $this->assertSame('issued', $this->unitStatus($allocation));
        $this->assertSame($requestStatus, DB::table('blood_requests')->where('id', $allocation->blood_request_id)->value('status'));

        Event::assertDispatchedTimes(TransfusionReactionReported::class, 1);
        Event::assertDispatched(TransfusionReactionReported::class, fn (TransfusionReactionReported $event): bool => $event->reactionId === $reaction->public_id
            && $event->transfusionId === $transfusion->public_id
            && $event->observedAt === now()->subHour()->toAtomString()
            && array_keys(get_object_vars($event)) === ['reactionId', 'transfusionId', 'observedAt']);
    }

    public function test_a_cited_reaction_type_can_be_attached_and_an_unknown_one_is_422(): void
    {
        // Baris KHUSUS TEST: seeder produksi sengaja kosong (belum ada kutipan).
        DB::table('transfusion_reaction_types')->insert([
            'code' => 'TEST_TYPE',
            'label' => 'Jenis uji',
            'source_reference' => 'test fixture',
            'provenance' => 'cited',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $transfusion = $this->transfusion();
        $headers = $this->headers($this->staff());

        $this->postJson("/api/v1/transfusions/{$transfusion->public_id}/reactions", [
            'observed_at' => now()->toIso8601String(),
            'reaction_type_code' => 'TEST_TYPE',
            'description' => 'Ruam.',
        ], $this->withKey($headers))
            ->assertCreated()
            ->assertJsonPath('data.reaction_type_code', 'TEST_TYPE');

        $this->postJson("/api/v1/transfusions/{$transfusion->public_id}/reactions", [
            'observed_at' => now()->toIso8601String(),
            'reaction_type_code' => 'MILD',
            'description' => 'Ruam.',
        ], $this->withKey($headers))
            ->assertStatus(422);
    }

    public function test_a_reaction_needs_a_description_and_a_past_observation_time(): void
    {
        $transfusion = $this->transfusion();
        $headers = $this->headers($this->staff());

        $this->postJson("/api/v1/transfusions/{$transfusion->public_id}/reactions", [
            'observed_at' => now()->toIso8601String(),
        ], $this->withKey($headers))->assertStatus(422);

        $this->postJson("/api/v1/transfusions/{$transfusion->public_id}/reactions", [
            'observed_at' => now()->addHour()->toIso8601String(),
            'description' => 'Belum terjadi.',
        ], $this->withKey($headers))->assertStatus(422);

        $this->assertSame(0, TransfusionReaction::query()->count());
    }

    public function test_the_request_rejects_backwards_time_and_volume_without_completion(): void
    {
        $allocation = $this->allocation();
        $headers = $this->headers($this->staff());

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", [
            'started_at' => now()->toIso8601String(),
            'completed_at' => now()->subHour()->toIso8601String(),
        ], $this->withKey($headers))->assertStatus(422);

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", [
            'started_at' => now()->toIso8601String(),
            'volume_transfused_ml' => 200,
        ], $this->withKey($headers))->assertStatus(422);

        $this->assertSame(0, Transfusion::query()->count());
    }

    /** Penjaga terakhir ada di database, bukan hanya di FormRequest. */
    public function test_the_database_rejects_backwards_time_incomplete_volume_and_duplicates(): void
    {
        $allocation = $this->allocation();
        $base = [
            'public_id' => (string) Str::uuid(),
            'blood_request_allocation_id' => $allocation->id,
            'recorded_by' => User::factory()->create()->id,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $cases = [
            'transfusions_time_order' => ['completed_at' => now()->subHour()],
            'transfusions_completion_shape' => ['volume_transfused_ml' => 200],
        ];

        foreach ($cases as $constraint => $override) {
            try {
                DB::transaction(fn () => DB::table('transfusions')->insert($override + $base));
                $this->fail("{$constraint} seharusnya menolak.");
            } catch (QueryException $e) {
                $this->assertStringContainsString($constraint, $e->getMessage());
            }
        }

        DB::table('transfusions')->insert($base);

        try {
            DB::transaction(fn () => DB::table('transfusions')->insert(['public_id' => (string) Str::uuid()] + $base));
            $this->fail('Index unik alokasi seharusnya menolak.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('transfusions_blood_request_allocation_id_unique', $e->getMessage());
        }
    }

    public function test_a_reaction_traces_back_to_the_donor_through_relations(): void
    {
        $donor = Donor::factory()->create();
        $appointment = new Appointment(['scheduled_for' => null]);
        $appointment->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            // Literal: ModRequest tidak boleh mengimpor DomDonor (deptrac).
            'status' => 'completed',
            'arrived_at' => now()->subDays(31),
            'screened_at' => now()->subDays(31),
            'completed_at' => now()->subDays(30),
        ])->save();
        $donation = new Donation(['volume_ml' => 450, 'started_at' => now()->subDays(30)->subMinutes(10), 'completed_at' => now()->subDays(30), 'note' => null]);
        $donation->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'appointment_id' => $appointment->id,
        ])->save();

        $allocation = $this->allocation();
        DB::table('blood_batches')->where('id', $allocation->blood_batch_id)->update(['donation_id' => $donation->id]);
        $transfusion = $this->transfusion($allocation);
        app(ReportTransfusionReaction::class)->handle($transfusion, User::factory()->create(), now()->toDateTimeImmutable(), 'Demam.');

        $reaction = TransfusionReaction::query()->with('transfusion.batch.donation.donor')->sole();

        $this->assertSame($donor->id, $reaction->transfusion->batch?->donation?->donor->id);
    }

    public function test_another_facility_can_neither_record_nor_read_nor_report(): void
    {
        $allocation = $this->allocation();
        $transfusion = $this->transfusion($allocation);
        $headers = $this->headers($this->staff(Facility::factory()->create()));

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/transfusion", ['started_at' => now()->toIso8601String()], $this->withKey($headers))
            ->assertForbidden();
        $this->getJson("/api/v1/transfusions/{$transfusion->public_id}", $headers)->assertForbidden();
        $this->postJson("/api/v1/transfusions/{$transfusion->public_id}/reactions", ['observed_at' => now()->toIso8601String(), 'description' => 'X.'], $this->withKey($headers))
            ->assertForbidden();
    }

    public function test_the_reaction_table_has_no_severity_and_no_patient_identity(): void
    {
        $columns = Schema::getColumnListing('transfusion_reactions');

        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/severity|grade|patient|nik|mrn|name/', $column);
        }
    }

    public function test_reaction_types_accept_only_cited_provenance_and_the_seed_is_empty(): void
    {
        $this->assertSame(0, DB::table('transfusion_reaction_types')->count());

        $this->expectException(QueryException::class);

        DB::table('transfusion_reaction_types')->insert([
            'code' => 'MILD',
            'label' => 'Ringan',
            'source_reference' => 'ingatan',
            'provenance' => 'derived',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_transfusions_hold_no_copied_donor_column(): void
    {
        // Telusur lewat relasi; kolom salinan bisa bohong setelah MergeDonors.
        $this->assertNotContains('donor_id', Schema::getColumnListing('transfusions'));
    }
}
