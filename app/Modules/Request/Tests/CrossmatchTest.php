<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequestAllocation;
use App\Models\Crossmatch;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\RecordCrossmatch;
use App\Modules\Request\Application\ReserveAllocatedUnits;
use App\Modules\Request\Domain\CrossmatchResult;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
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
 * pertama meski tokennya berganti. Langkah peran lain lewat service.
 */
final class CrossmatchTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

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

    /** Unit O- yang sudah dialokasikan ke satu permintaan, dan (default) sudah dipesan. */
    private function allocation(bool $reserve = true): BloodRequestAllocation
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

        if ($reserve) {
            app(ReserveAllocatedUnits::class)->handle($request);
        }

        return $allocation->fresh() ?? $allocation;
    }

    private function record(BloodRequestAllocation $allocation, CrossmatchResult $result, ?string $note = null): Crossmatch
    {
        return app(RecordCrossmatch::class)->handle($allocation, $result, User::factory()->create(), 'IAT', $note);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function issue(array $headers, BloodRequestAllocation $allocation): TestResponse
    {
        return $this->postJson("/api/v1/allocations/{$allocation->public_id}/issue", [], $this->withKey($headers));
    }

    private function unitStatus(BloodRequestAllocation $allocation): string
    {
        return (string) DB::table('blood_batches')->where('id', $allocation->blood_batch_id)->value('status');
    }

    public function test_a_compatible_result_lets_the_unit_be_issued(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::COMPATIBLE);

        $this->issue($this->headers($this->staff()), $allocation)
            ->assertOk()
            ->assertJsonPath('data.unit_status', 'issued')
            ->assertJsonPath('data.allocation.reserved_at', null)
            ->assertJsonPath('data.allocation.reservation_release_reason', 'issued')
            ->assertJsonPath('data.crossmatches.0.result', 'compatible');

        $this->assertSame('issued', $this->unitStatus($allocation));
    }

    public function test_issuing_without_any_crossmatch_is_409(): void
    {
        $allocation = $this->allocation();

        $this->issue($this->headers($this->staff()), $allocation)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');

        $this->assertSame('reserved', $this->unitStatus($allocation));
    }

    public function test_an_incompatible_result_blocks_issue(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::INCOMPATIBLE, 'Aglutinasi pada fase AHG.');

        $this->issue($this->headers($this->staff()), $allocation)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');

        // Saga kartu 360: incompatible melepas alokasinya, unit kembali ke stok.
        $this->assertSame('released', $this->unitStatus($allocation));
        $this->assertNotNull($allocation->fresh()?->released_at);
    }

    /** Inconclusive bukan incompatible: unitnya tidak ditarik, hanya belum boleh terbit. */
    public function test_an_inconclusive_result_blocks_issue_but_keeps_the_unit_reserved_and_allocated(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::INCONCLUSIVE, 'Sampel hemolisis, ambil ulang.');

        $this->issue($this->headers($this->staff()), $allocation)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');

        $this->assertSame('reserved', $this->unitStatus($allocation));
        $fresh = $allocation->fresh();
        $this->assertNull($fresh?->released_at);
        $this->assertNotNull($fresh->reserved_at);
    }

    public function test_the_latest_result_wins_when_compatible_is_followed_by_incompatible(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::COMPATIBLE);
        $this->record($allocation, CrossmatchResult::INCOMPATIBLE, 'Crossmatch ulang: aglutinasi.');

        $this->issue($this->headers($this->staff()), $allocation)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');

        $this->assertSame('released', $this->unitStatus($allocation));
    }

    public function test_the_latest_result_wins_when_inconclusive_is_followed_by_compatible(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::INCONCLUSIVE, 'Sampel lisis.');
        $this->record($allocation, CrossmatchResult::COMPATIBLE);

        $this->issue($this->headers($this->staff()), $allocation)->assertOk();

        $this->assertSame('issued', $this->unitStatus($allocation));
    }

    public function test_recording_appends_and_never_changes_the_unit(): void
    {
        $allocation = $this->allocation();
        $headers = $this->headers($this->staff());

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/crossmatches", ['result' => 'inconclusive', 'note' => 'Lisis.'], $this->withKey($headers))
            ->assertCreated()
            ->assertJsonPath('data.result', 'inconclusive')
            ->assertJsonPath('data.allocation_id', $allocation->public_id)
            ->assertJsonMissingPath('data.performed_by');
        $this->postJson("/api/v1/allocations/{$allocation->public_id}/crossmatches", ['result' => 'compatible', 'method' => 'IAT'], $this->withKey($headers))
            ->assertCreated();

        $this->assertSame(['inconclusive', 'compatible'], Crossmatch::query()->orderBy('id')->pluck('result')->map->value->all());
        $this->assertSame('reserved', $this->unitStatus($allocation));
    }

    public function test_a_failed_result_without_a_note_is_422(): void
    {
        $allocation = $this->allocation();

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/crossmatches", ['result' => 'incompatible'], $this->withKey($this->headers($this->staff())))
            ->assertStatus(422)
            ->assertJsonPath('error.details.note.0.rule', 'required_unless');

        $this->assertSame(0, Crossmatch::query()->count());
    }

    public function test_the_database_rejects_a_failed_result_without_a_note(): void
    {
        $allocation = $this->allocation();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('crossmatches_failure_needs_note');

        DB::table('crossmatches')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_request_allocation_id' => $allocation->id,
            'performed_by' => User::factory()->create()->id,
            'result' => 'incompatible',
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_crossmatch_rows_are_append_only(): void
    {
        $this->record($this->allocation(), CrossmatchResult::INCOMPATIBLE, 'Aglutinasi.');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('crossmatches is append-only');

        DB::table('crossmatches')->update(['result' => 'compatible']);
    }

    public function test_crossmatch_rows_cannot_be_deleted(): void
    {
        $this->record($this->allocation(), CrossmatchResult::INCOMPATIBLE, 'Aglutinasi.');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('crossmatches is append-only');

        DB::table('crossmatches')->delete();
    }

    /** Jalur service TransitionBloodBatch diuji di Inventory/Tests/IssueGateTest (batas modul). */
    public function test_the_status_endpoint_cannot_issue_even_with_a_compatible_crossmatch(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::COMPATIBLE);
        $unit = BloodBatch::query()->findOrFail($allocation->blood_batch_id);

        $this->patchJson("/api/v1/blood-batches/{$unit->public_id}/status", ['status' => 'issued'], $this->withKey($this->headers($this->staff())))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BLOOD_BATCH_TRANSITION_REJECTED');

        $this->assertSame('reserved', $this->unitStatus($allocation));
    }

    public function test_an_allocated_but_unreserved_unit_cannot_be_issued(): void
    {
        $allocation = $this->allocation(reserve: false);
        $this->record($allocation, CrossmatchResult::COMPATIBLE);

        $this->issue($this->headers($this->staff()), $allocation)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');

        $this->assertSame('released', $this->unitStatus($allocation));
    }

    public function test_a_released_allocation_can_neither_be_crossmatched_nor_issued(): void
    {
        $allocation = $this->allocation(reserve: false);
        $this->record($allocation, CrossmatchResult::COMPATIBLE);
        app(AllocateUnitsFefo::class)->release($allocation, 'Batal.');
        $headers = $this->headers($this->staff());

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/crossmatches", ['result' => 'compatible'], $this->withKey($headers))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');
        $this->issue($headers, $allocation)->assertStatus(409)->assertJsonPath('error.code', 'CROSSMATCH_REJECTED');

        $this->assertSame('released', $this->unitStatus($allocation));
    }

    public function test_an_issued_unit_is_not_touched_by_the_reservation_timeout(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::COMPATIBLE);
        $this->issue($this->headers($this->staff()), $allocation)->assertOk();

        $this->artisan('request:expire-reservations', ['--force' => true])->expectsOutputToContain('Melepas 0 reservasi')->assertSuccessful();

        $this->assertSame('issued', $this->unitStatus($allocation));
        $this->assertSame('issued', $allocation->fresh()?->reservation_release_reason);
    }

    public function test_another_facility_cannot_crossmatch_or_issue(): void
    {
        $allocation = $this->allocation();
        $this->record($allocation, CrossmatchResult::COMPATIBLE);
        $headers = $this->headers($this->staff(Facility::factory()->create()));

        $this->postJson("/api/v1/allocations/{$allocation->public_id}/crossmatches", ['result' => 'compatible'], $this->withKey($headers))->assertForbidden();
        $this->issue($headers, $allocation)->assertForbidden();

        $this->assertSame('reserved', $this->unitStatus($allocation));
        $this->assertSame(1, Crossmatch::query()->count());
    }

    public function test_the_result_enum_only_lets_compatible_issue(): void
    {
        $this->assertTrue(CrossmatchResult::COMPATIBLE->permitsIssue());
        $this->assertFalse(CrossmatchResult::INCOMPATIBLE->permitsIssue());
        $this->assertFalse(CrossmatchResult::INCONCLUSIVE->permitsIssue());
    }
}
