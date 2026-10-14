<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\Exceptions\BloodRequestNotAllocatable;
use App\Modules\Request\Domain\RequestStatus;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test: guard Sanctum memegang user dari request
 * pertama meski tokennya berganti.
 */
final class FefoAllocationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    private function fefo(): AllocateUnitsFefo
    {
        return app(AllocateUnitsFefo::class);
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
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    /**
     * Unit released dengan kedaluwarsa tertentu. collected_at dimundurkan jauh supaya
     * CHECK blood_batches_expiry_provenance (expires_at > collected_at) terpenuhi.
     */
    private function unit(
        ?string $expiresIn = '+5 days',
        string $group = 'O',
        string $rh = 'negative',
        string $component = 'packed_red_cells',
        string $status = 'released',
        ?Facility $facility = null,
    ): BloodBatch {
        $unit = BloodBatch::factory()->create([
            'component' => $component,
            'facility_id' => ($facility ?? $this->facility)->id,
        ]);

        $unit->forceFill([
            'blood_group' => $group,
            'rh_factor' => $rh,
            'collected_at' => now()->subDays(30),
            'expires_at' => $expiresIn === null ? null : Carbon::parse($expiresIn),
            'status' => $status,
            'released_at' => $status === 'released' ? now()->subDays(29) : null,
        ])->save();

        return $unit;
    }

    private function request(
        int $quantity = 3,
        string $group = 'O',
        string $rh = 'negative',
        BloodComponent $component = BloodComponent::PACKED_RED_CELLS,
        string $neededBy = '+1 day',
    ): BloodRequest {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            $component,
            BloodGroup::from($group),
            RhFactor::from($rh),
            $quantity,
            RequestUrgency::URGENT,
            new DateTimeImmutable($neededBy),
            'RM-'.Str::random(6),
        );
    }

    /** @return list<string> */
    private function candidateIds(BloodRequest $request, int $limit = 10): array
    {
        return $this->fefo()->candidatesFor($request, $limit)->pluck('public_id')->all();
    }

    /** @return list<string> */
    private function allocatedUnitIds(BloodRequest $request): array
    {
        return BloodRequestAllocation::query()
            ->where('blood_request_id', $request->id)
            ->whereNull('released_at')
            ->orderBy('id')
            ->get()
            ->map(fn (BloodRequestAllocation $a): string => (string) BloodBatch::query()->whereKey($a->blood_batch_id)->value('public_id'))
            ->all();
    }

    private function allocator(): int
    {
        return User::factory()->create(['facility_id' => $this->facility->id])->id;
    }

    public function test_the_unit_expiring_soonest_is_allocated_first(): void
    {
        // Disisipkan TIDAK urut kedaluwarsa, supaya urutan id tidak kebetulan sama dengan FEFO.
        $third = $this->unit('+9 days');
        $first = $this->unit('+3 days');
        $second = $this->unit('+6 days');
        $request = $this->request(quantity: 3);

        $this->assertSame([$first->public_id, $second->public_id, $third->public_id], $this->candidateIds($request));

        // Satu per satu: urutan alokasi ketiganya, bukan hanya yang pertama.
        foreach ([$first, $second, $third] as $expected) {
            [$allocation] = $this->fefo()->handle($request, $this->allocator(), max: 1);
            $this->assertSame($expected->id, $allocation->blood_batch_id);
        }
    }

    public function test_units_with_identical_expiry_are_ordered_deterministically_by_id(): void
    {
        $a = $this->unit('+4 days');
        $b = $this->unit('+4 days');
        $c = $this->unit('+4 days');
        $request = $this->request();

        $this->assertSame([$a->public_id, $b->public_id, $c->public_id], $this->candidateIds($request));
        $this->assertSame($this->candidateIds($request), $this->candidateIds($request));
    }

    public function test_a_unit_without_an_expiry_date_is_never_allocated(): void
    {
        $this->unit(null);
        $request = $this->request();

        $this->assertSame([], $this->candidateIds($request));
        $this->assertSame([], $this->fefo()->handle($request, $this->allocator()));
        $this->assertSame(RequestStatus::OPEN, $request->fresh()?->status);
    }

    public function test_a_unit_expiring_before_needed_by_is_never_allocated(): void
    {
        $dead = $this->unit('+2 days');
        $alive = $this->unit('+8 days');
        $request = $this->request(neededBy: '+3 days');

        $this->assertSame([$alive->public_id], $this->candidateIds($request));
        $this->assertNotContains($dead->public_id, $this->candidateIds($request));
    }

    public function test_an_incompatible_unit_is_skipped_even_if_it_expires_soonest(): void
    {
        // Penerima O-: sel darah merah hanya dari O-. A+ yang paling cepat kedaluwarsa harus dilewati.
        $incompatible = $this->unit('+2 days', group: 'A', rh: 'positive');
        $compatible = $this->unit('+7 days', group: 'O', rh: 'negative');
        $request = $this->request(quantity: 1);

        [$allocation] = $this->fefo()->handle($request, $this->allocator());

        $this->assertSame($compatible->id, $allocation->blood_batch_id);
        $this->assertNotContains($incompatible->public_id, $this->candidateIds($request, 10));
    }

    public function test_a_wall_of_incompatible_units_does_not_hide_a_compatible_one(): void
    {
        // Lebih banyak dari satu batch keyset: hasil tidak boleh terpotong diam-diam.
        for ($i = 0; $i < 60; $i++) {
            $this->unit('+2 days', group: 'A', rh: 'positive');
        }
        $compatible = $this->unit('+9 days');

        $this->assertSame([$compatible->public_id], $this->candidateIds($this->request(quantity: 1)));
    }

    public function test_a_quarantined_unit_is_never_allocated(): void
    {
        $this->unit('+2 days', status: 'quarantined');

        $this->assertSame([], $this->candidateIds($this->request()));
    }

    public function test_a_unit_from_another_facility_is_never_allocated(): void
    {
        $this->unit('+2 days', facility: Facility::factory()->create());

        $this->assertSame([], $this->candidateIds($this->request()));
    }

    public function test_a_unit_with_an_open_transfer_is_never_allocated(): void
    {
        $inTransit = $this->unit('+2 days');
        $request = $this->request();
        $this->assertSame([$inTransit->public_id], $this->candidateIds($request));

        foreach (['requested', 'approved', 'shipped'] as $status) {
            DB::table('stock_transfers')->delete();
            DB::table('stock_transfers')->insert([
                'public_id' => (string) Str::uuid(),
                'blood_batch_id' => $inTransit->id,
                'from_facility_id' => $this->facility->id,
                'to_facility_id' => Facility::factory()->create()->id,
                'status' => $status,
                'requested_by' => User::factory()->create()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->assertSame([], $this->candidateIds($request), "transfer {$status}");
        }
    }

    public function test_a_closed_transfer_no_longer_holds_the_unit(): void
    {
        $unit = $this->unit('+2 days');
        DB::table('stock_transfers')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_batch_id' => $unit->id,
            'from_facility_id' => $this->facility->id,
            'to_facility_id' => Facility::factory()->create()->id,
            'status' => 'rejected',
            'requested_by' => User::factory()->create()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([$unit->public_id], $this->candidateIds($this->request()));
    }

    public function test_a_unit_already_allocated_is_never_allocated_twice(): void
    {
        $unit = $this->unit('+2 days');
        $first = $this->request(quantity: 1);
        $second = $this->request(quantity: 1);

        $this->assertCount(1, $this->fefo()->handle($first, $this->allocator()));
        $this->assertSame([], $this->candidateIds($second));
        $this->assertSame([], $this->fefo()->handle($second, $this->allocator()));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_request_allocations_one_active_per_unit');

        DB::table('blood_request_allocations')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_request_id' => $second->id,
            'blood_batch_id' => $unit->id,
            'allocated_by' => $this->allocator(),
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_platelet_request_allocates_nothing(): void
    {
        // Stok trombosit yang ABO/RhD-nya identik dengan penerima.
        $this->unit('+2 days', component: 'platelet_concentrate');
        $request = $this->request(component: BloodComponent::PLATELET_CONCENTRATE);

        $this->assertSame([], $this->candidateIds($request));
        $this->assertSame([], $this->fefo()->handle($request, $this->allocator()));
        $this->assertSame(RequestStatus::OPEN, $request->fresh()?->status);
    }

    public function test_a_partial_allocation_sets_partially_fulfilled(): void
    {
        $this->unit('+2 days');
        $request = $this->request(quantity: 3);

        $this->assertCount(1, $this->fefo()->handle($request, $this->allocator()));

        $this->assertSame(RequestStatus::PARTIALLY_FULFILLED, $request->fresh()?->status);
    }

    public function test_a_complete_allocation_sets_fulfilled(): void
    {
        $this->unit('+2 days');
        $this->unit('+3 days');
        $request = $this->request(quantity: 2);

        $this->assertCount(2, $this->fefo()->handle($request, $this->allocator()));

        $this->assertSame(RequestStatus::FULFILLED, $request->fresh()?->status);
    }

    public function test_allocating_a_full_request_again_allocates_nothing(): void
    {
        $this->unit('+2 days');
        $this->unit('+3 days');
        $request = $this->request(quantity: 1);

        $this->fefo()->handle($request, $this->allocator());

        $this->assertSame([], $this->fefo()->handle($request, $this->allocator()));
        $this->assertCount(1, $this->allocatedUnitIds($request));
    }

    public function test_a_closed_request_cannot_receive_allocations(): void
    {
        $this->unit('+2 days');
        $request = $this->request();
        $request->forceFill(['status' => RequestStatus::CANCELLED])->save();

        $this->expectException(BloodRequestNotAllocatable::class);

        $this->fefo()->handle($request, $this->allocator());
    }

    public function test_releasing_an_allocation_frees_the_unit_and_lowers_the_status(): void
    {
        $unit = $this->unit('+2 days');
        $request = $this->request(quantity: 1);
        [$allocation] = $this->fefo()->handle($request, $this->allocator());
        $this->assertSame(RequestStatus::FULFILLED, $request->fresh()?->status);

        $this->fefo()->release($allocation, 'Crossmatch tidak cocok.');

        $this->assertSame(RequestStatus::OPEN, BloodRequest::query()->findOrFail($request->id)->status);
        $this->assertSame('Crossmatch tidak cocok.', $allocation->fresh()?->release_reason);
        $this->assertSame([$unit->public_id], $this->candidateIds($request));
    }

    public function test_releasing_one_of_two_lowers_fulfilled_to_partially_fulfilled(): void
    {
        $this->unit('+2 days');
        $this->unit('+3 days');
        $request = $this->request(quantity: 2);
        [$first] = $this->fefo()->handle($request, $this->allocator());

        $this->fefo()->release($first, 'Kantong rusak saat diambil.');

        $this->assertSame(RequestStatus::PARTIALLY_FULFILLED, $request->fresh()?->status);
    }

    public function test_releasing_twice_is_idempotent(): void
    {
        $this->unit('+2 days');
        $request = $this->request(quantity: 1);
        [$allocation] = $this->fefo()->handle($request, $this->allocator());

        $this->fefo()->release($allocation, 'Pertama.');
        $releasedAt = $allocation->fresh()?->released_at;

        $this->fefo()->release(BloodRequestAllocation::query()->findOrFail($allocation->id), 'Kedua.');

        $fresh = $allocation->fresh();
        $this->assertEquals($releasedAt, $fresh?->released_at);
        $this->assertSame('Pertama.', $fresh->release_reason);
    }

    public function test_allocation_never_changes_the_unit_status(): void
    {
        $unit = $this->unit('+2 days');
        $request = $this->request(quantity: 1);

        [$allocation] = $this->fefo()->handle($request, $this->allocator());
        $this->assertSame('released', DB::table('blood_batches')->where('id', $unit->id)->value('status'));

        $this->fefo()->release($allocation, 'Batal.');
        $this->assertSame('released', DB::table('blood_batches')->where('id', $unit->id)->value('status'));
    }

    public function test_the_candidate_query_uses_the_fefo_index(): void
    {
        $this->unit('+2 days');
        $request = $this->request();

        // Tabel test terlalu kecil: tanpa ini planner memilih seq scan (pola FacilityCrudTest).
        // Sort juga dimatikan supaya hasilnya tidak bergantung statistik tabel yang
        // baru dimigrasi: index yang TIDAK bisa memberi urutan tetap memaksa node
        // Sort muncul, jadi assertion di bawah tetap menggigit.
        DB::statement('SET enable_seqscan = off;');
        DB::statement('SET enable_sort = off;');
        DB::statement('SET enable_incremental_sort = off;');

        $query = $this->fefo()->candidateQuery($request)->limit(50);
        $plan = implode("\n", array_map(
            fn (object $row): string => (string) $row->{'QUERY PLAN'},
            DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()),
        ));

        $this->assertStringContainsString('blood_batches_fefo_index', $plan);
        // Urutan dilayani index, bukan sort terpisah.
        $this->assertStringNotContainsString('Sort', $plan);
    }

    public function test_the_endpoints_allocate_and_release_through_http(): void
    {
        $soon = $this->unit('+2 days');
        $later = $this->unit('+5 days');
        $request = $this->request(quantity: 2);
        $headers = $this->headers($this->staff());

        $this->getJson("/api/v1/blood-requests/{$request->public_id}/candidates", $headers)
            ->assertOk()
            ->assertJsonPath('data.remaining', 2)
            ->assertJsonPath('data.candidates.0.unit_id', $soon->public_id)
            ->assertJsonPath('data.candidates.1.unit_id', $later->public_id);

        $allocationId = $this->postJson("/api/v1/blood-requests/{$request->public_id}/allocations", ['max' => 1], $headers)
            ->assertCreated()
            ->assertJsonPath('data.blood_request.status', 'partially_fulfilled')
            ->assertJsonPath('data.allocations.0.unit_id', $soon->public_id)
            ->assertJsonMissingPath('data.allocations.0.allocated_by')
            ->json('data.allocations.0.id');

        $this->deleteJson("/api/v1/allocations/{$allocationId}", ['reason' => 'Batal.'], $headers)
            ->assertOk()
            ->assertJsonPath('data.release_reason', 'Batal.');

        $this->assertSame(RequestStatus::OPEN, $request->fresh()?->status);
    }

    public function test_another_facility_cannot_see_candidates_or_allocate(): void
    {
        $this->unit('+2 days');
        $request = $this->request();
        $headers = $this->headers($this->staff(Facility::factory()->create()));

        $this->getJson("/api/v1/blood-requests/{$request->public_id}/candidates", $headers)->assertForbidden();
        $this->postJson("/api/v1/blood-requests/{$request->public_id}/allocations", [], $headers)->assertForbidden();

        $this->assertSame(0, BloodRequestAllocation::query()->count());
    }
}
