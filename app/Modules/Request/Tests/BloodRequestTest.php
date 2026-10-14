<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\RequestStatus;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
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
 * pertama meski tokennya berganti. Permintaan fasilitas lain ditulis lewat service.
 */
final class BloodRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** Nama role sengaja literal: ModRequest tidak boleh bergantung ke DomIdentity. */
    private function userWithRole(?int $facilityId, string $role = 'hospital_staff'): User
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'patient_reference' => 'RM-2026-0042',
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'positive',
            'quantity' => 4,
            'urgency' => 'urgent',
            'needed_by' => now()->addDay()->toIso8601String(),
            'note' => 'Operasi elektif.',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function store(User $user, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/blood-requests', $payload, $this->headers($user));
    }

    private function requestAt(Facility $facility, string $neededBy = '+1 day'): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $facility->id,
            User::factory()->create(['facility_id' => $facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::A,
            RhFactor::NEGATIVE,
            2,
            RequestUrgency::ROUTINE,
            new DateTimeImmutable($neededBy),
            'RM-'.Str::random(6),
        );
    }

    public function test_hospital_staff_creates_a_blood_request(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id);

        $response = $this->store($staff, $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.patient_reference', 'RM-2026-0042')
            ->assertJsonPath('data.component', 'packed_red_cells')
            ->assertJsonPath('data.blood_group', 'O')
            ->assertJsonPath('data.rh_factor', 'positive')
            ->assertJsonPath('data.quantity', 4)
            ->assertJsonPath('data.urgency', 'urgent')
            ->assertJsonPath('data.note', 'Operasi elektif.');

        $this->assertTrue(Str::isUuid($response->json('data.id')));
        $this->assertSame($staff->id, BloodRequest::query()->sole()->requested_by);
    }

    public function test_the_request_belongs_to_the_staff_facility_not_the_payload(): void
    {
        $facility = Facility::factory()->create();
        Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.facility_id', $facility->public_id);

        $this->assertSame($facility->id, BloodRequest::query()->sole()->facility_id);
    }

    public function test_a_request_is_born_open(): void
    {
        $facility = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');

        $this->assertSame(RequestStatus::OPEN, BloodRequest::query()->sole()->status);
    }

    public function test_a_status_in_the_payload_is_422_not_honoured(): void
    {
        $facility = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload(['status' => 'fulfilled']))
            ->assertStatus(422)
            ->assertJsonPath('error.details.status.0.rule', 'not_allowed');

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_a_needed_by_in_the_past_is_422(): void
    {
        $facility = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload(['needed_by' => now()->subHour()->toIso8601String()]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.needed_by.0.rule', 'after');

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_a_missing_needed_by_is_422(): void
    {
        $facility = Facility::factory()->create();
        $payload = $this->payload();
        unset($payload['needed_by']);

        $this->store($this->userWithRole($facility->id), $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.details.needed_by.0.rule', 'required');
    }

    public function test_a_quantity_of_zero_is_422(): void
    {
        $facility = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload(['quantity' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.quantity.0.rule', 'min');

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_a_quantity_above_twenty_is_422(): void
    {
        $facility = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload(['quantity' => 21]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.quantity.0.rule', 'max');

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_an_unknown_blood_group_is_422(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id);

        // 'a' dan '0': nilai yang lolos kalau daftar dibandingkan longgar.
        foreach (['C', 'a', '0'] as $group) {
            $this->store($staff, $this->payload(['blood_group' => $group]))
                ->assertStatus(422)
                ->assertJsonPath('error.details.blood_group.0.rule', 'enum');
        }

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_an_unknown_body_key_is_422(): void
    {
        $facility = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload(['blood_batch_id' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.blood_batch_id.0.rule', 'not_allowed');

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_a_facility_id_in_the_payload_is_422(): void
    {
        $facility = Facility::factory()->create();
        $other = Facility::factory()->create();

        $this->store($this->userWithRole($facility->id), $this->payload(['facility_id' => $other->public_id]))
            ->assertStatus(422)
            ->assertJsonPath('error.details.facility_id.0.rule', 'not_allowed');

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_the_list_only_shows_the_staff_own_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $theirs = $this->requestAt($facilityA);
        $ours = $this->requestAt($facilityB);

        $ids = array_column(
            $this->getJson('/api/v1/blood-requests', $this->headers($this->userWithRole($facilityB->id)))->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$ours->public_id], $ids);
        $this->assertNotContains($theirs->public_id, $ids);
    }

    public function test_a_user_without_request_create_is_403(): void
    {
        $facility = Facility::factory()->create();

        // User berfasilitas tanpa role apa pun: tidak memegang request.create.
        $user = User::factory()->create(['facility_id' => $facility->id]);

        $this->store($user, $this->payload())->assertForbidden();

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_a_global_operator_without_a_facility_cannot_create(): void
    {
        $this->store($this->userWithRole(null, 'admin'), $this->payload())->assertForbidden();

        $this->assertSame(0, BloodRequest::query()->count());
    }

    public function test_an_impossible_request_is_still_recorded(): void
    {
        $facility = Facility::factory()->create();

        // Tidak ada stok AB- sama sekali: kartu ini tidak memeriksa kompatibilitas
        // maupun ketersediaan (kartu 315/320).
        $this->store($this->userWithRole($facility->id), $this->payload(['blood_group' => 'AB', 'rh_factor' => 'negative', 'urgency' => 'emergency']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_the_second_page_is_reachable_with_the_cursor_from_the_first_page(): void
    {
        $facility = Facility::factory()->create();
        $this->requestAt($facility, '+1 day');
        $this->requestAt($facility, '+2 days');
        $this->requestAt($facility, '+3 days');
        $headers = $this->headers($this->userWithRole($facility->id));

        $first = $this->getJson('/api/v1/blood-requests?per_page=2&sort=needed_by', $headers)->assertOk();
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->getJson('/api/v1/blood-requests?per_page=2&sort=needed_by&cursor='.$cursor, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([], array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        ));
    }

    public function test_the_response_never_contains_internal_ids(): void
    {
        $facility = Facility::factory()->create();
        $staff = $this->userWithRole($facility->id);

        $created = $this->store($staff, $this->payload())->assertCreated()->json('data');
        $listed = $this->getJson('/api/v1/blood-requests', $this->headers($staff))->assertOk()->json('data.0');

        foreach ([$created, $listed] as $row) {
            $this->assertArrayNotHasKey('requested_by', $row);
            $this->assertTrue(Str::isUuid($row['id']));
            $this->assertTrue(Str::isUuid($row['facility_id']));
        }
    }

    public function test_the_database_rejects_a_zero_quantity(): void
    {
        $this->requestAt(Facility::factory()->create());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('blood_requests_quantity_shape');

        DB::table('blood_requests')->update(['quantity' => 0]);
    }
}
