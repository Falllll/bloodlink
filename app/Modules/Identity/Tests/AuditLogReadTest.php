<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AuditLogReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function assignRole(User $user, RoleEnum $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($user->facility_id));
        $user->assignRole($role->value);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $user->createToken('api')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_a_global_admin_can_list_audit_logs(): void
    {
        Facility::factory()->create();

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/audit-logs', $this->bearer($admin));

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    public function test_a_facility_admin_only_sees_its_own_facility_rows(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $adminA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($adminA, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/audit-logs', $this->bearer($adminA));

        $response->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertNotSame($facilityB->id, $row['actor_facility_id']);
        }
    }

    public function test_filtering_another_facility_id_returns_nothing(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();

        $adminA = User::factory()->create(['facility_id' => $facilityA->id]);
        $this->assignRole($adminA, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/audit-logs?filter[actor_facility_id]='.$facilityB->id, $this->bearer($adminA));

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_hospital_staff_without_audit_view_gets_403(): void
    {
        $facility = Facility::factory()->create();

        $staff = User::factory()->create(['facility_id' => $facility->id]);
        $this->assignRole($staff, RoleEnum::HOSPITAL_STAFF);

        $response = $this->getJson('/api/v1/audit-logs', $this->bearer($staff));

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_an_unknown_filter_key_is_rejected(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/audit-logs?filter[ip]=1.2.3.4', $this->bearer($admin));

        $response->assertStatus(422)
            ->assertJsonPath('error.details.filter.0.rule', 'unsupported_filter');
    }

    public function test_an_unknown_sort_column_is_rejected(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/audit-logs?sort=changes', $this->bearer($admin));

        $response->assertStatus(422)
            ->assertJsonPath('error.details.sort.0.rule', 'invalid_sort');
    }

    public function test_posting_to_audit_logs_is_not_allowed(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->postJson('/api/v1/audit-logs', [], $this->bearer($admin));

        $response->assertStatus(405)
            ->assertJsonPath('error.code', 'METHOD_NOT_ALLOWED');
    }

    public function test_encrypted_donor_columns_never_appear_in_the_response(): void
    {
        $donor = Donor::factory()->create([
            'phone' => '081234567890',
            'email' => 'donor-secret@example.test',
        ]);

        $donor->forceFill([
            'phone' => '089999999999',
            'email' => 'donor-changed@example.test',
            'address' => 'Alamat Baru Rahasia',
            'city' => 'Kota Baru', // <- satu kolom yang lolos auditExcept(), supaya baris 'updated' benar-benar tertulis
        ])->save();

        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $response = $this->getJson('/api/v1/audit-logs', $this->bearer($admin));

        $response->assertOk();

        $log = AuditLog::where('auditable_type', Donor::class)
            ->where('auditable_id', $donor->id)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertArrayNotHasKey('phone', $log->changes['after']);
        $this->assertArrayNotHasKey('email', $log->changes['after']);
        $this->assertArrayNotHasKey('address', $log->changes['after']);

        $body = $response->getContent();

        $this->assertStringNotContainsString('089999999999', (string) $body);
        $this->assertStringNotContainsString('donor-changed@example.test', (string) $body);
        $this->assertStringNotContainsString('Alamat Baru Rahasia', (string) $body);
    }

    public function test_cursor_pagination_never_duplicates_or_skips_rows(): void
    {
        $admin = User::factory()->create(['facility_id' => null]);
        $this->assignRole($admin, RoleEnum::ADMIN);

        $auditableType = 'App\\Models\\CursorPaginationFixture';
        $occurredAt = now();

        for ($i = 0; $i < 30; $i++) {
            AuditLog::create([
                'auditable_type' => $auditableType,
                'auditable_id' => $i + 1,
                'action' => 'created',
                'actor_id' => null,
                'actor_facility_id' => null,
                'changes' => ['before' => [], 'after' => []],
                'trace_id' => null,
                'ip' => null,
                'occurred_at' => $occurredAt,
            ]);
        }

        $baseQuery = [
            'per_page' => 10,
            'sort' => 'occurred_at',
            'filter' => ['auditable_type' => $auditableType],
        ];

        $ids = [];
        $cursor = null;

        do {
            $query = $cursor === null ? $baseQuery : array_merge($baseQuery, ['cursor' => $cursor]);

            $response = $this->getJson('/api/v1/audit-logs?'.http_build_query($query), $this->bearer($admin));

            $response->assertOk();

            foreach ($response->json('data') as $row) {
                $ids[] = $row['id'];
            }

            $cursor = $response->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertCount(30, $ids);
        $this->assertCount(30, array_unique($ids));
    }
}
