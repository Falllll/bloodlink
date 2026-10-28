<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\DeviceToken;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Application\StaffRoles;
use App\Modules\Identity\Domain\Role;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Manajemen akun staf (kartu 487). Satu user terautentikasi per test: guard Sanctum
 * memegang user dari request pertama meski tokennya berganti. Efek pada akun lain
 * dibuktikan lewat database, bukan lewat token kedua.
 */
final class StaffAccountTest extends TestCase
{
    use RefreshDatabase;

    private Facility $rsA;

    private Facility $rsB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationTemplateSeeder::class);
        Mail::fake();
        $this->freezeSecond();

        $this->rsA = Facility::factory()->create(['is_active' => true]);
        $this->rsB = Facility::factory()->create(['is_active' => true]);
    }

    private function account(Role $role, ?Facility $facility): User
    {
        // UserFactory tidak mengisi public_id; register dan CreateStaffAccount mengisinya sendiri.
        $user = User::factory()->create(['facility_id' => $facility?->id, 'is_active' => true]);
        $user->forceFill(['public_id' => (string) Str::uuid()])->save();

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function as(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    /** @return array<string, string> */
    private function newStaff(Facility $facility, string $role = 'hospital_staff', string $email = 'baru@example.test'): array
    {
        return ['name' => 'Petugas Baru', 'email' => $email, 'facility_id' => $facility->public_id, 'role' => $role];
    }

    /** Peran di team fasilitas tertentu, dibaca langsung dari pivot Spatie. */
    private function roleIn(User $user, ?int $facilityId): ?string
    {
        $name = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.facility_id', FacilityScope::of($facilityId))
            ->value('roles.name');

        return is_string($name) ? $name : null;
    }

    // ---------------------------------------------------------------- create

    public function test_a_global_operator_creates_staff_in_any_facility_without_a_password_anywhere(): void
    {
        $global = $this->account(Role::ADMIN, null);

        $response = $this->postJson('/api/v1/users', $this->newStaff($this->rsB), $this->as($global))
            ->assertCreated()
            ->assertJsonPath('data.facility_id', $this->rsB->public_id)
            ->assertJsonPath('data.role', 'hospital_staff')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.token');

        $created = User::query()->where('email', 'baru@example.test')->sole();
        $this->assertSame($created->public_id, $response->json('data.id'));
        $this->assertStringNotContainsString('password', (string) $response->getContent());

        // Peran di team fasilitas RS B -- bukan di team global (team aktor), bukan di RS A.
        $this->assertSame('hospital_staff', $this->roleIn($created, $this->rsB->id));
        $this->assertNull($this->roleIn($created, null));
        $this->assertNull($this->roleIn($created, $this->rsA->id));
    }

    public function test_the_new_account_gets_a_single_use_invitation_by_email_only(): void
    {
        $global = $this->account(Role::ADMIN, null);

        $this->postJson('/api/v1/users', $this->newStaff($this->rsA), $this->as($global))->assertCreated();

        $created = User::query()->where('email', 'baru@example.test')->sole();
        $invite = DB::table('user_password_resets')->where('user_id', $created->id)->sole();
        $this->assertNull($invite->used_at);
        $this->assertSame(now()->addHours((int) config('security.staff_invitation_ttl_hours'))->toDateTimeString(), (string) $invite->expires_at);
        $this->assertSame(['email'], DB::table('notification_deliveries')->where('user_id', $created->id)->where('template_code', 'auth.staff_invitation')->pluck('channel')->all());
        Mail::assertSentCount(1);
    }

    public function test_a_facility_admin_creates_staff_only_in_their_own_facility(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $headers = $this->as($admin);

        $this->postJson('/api/v1/users', $this->newStaff($this->rsA, email: 'a@example.test'), $headers)->assertCreated();
        $this->postJson('/api/v1/users', $this->newStaff($this->rsB, email: 'b@example.test'), ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertForbidden();

        $this->assertSame(0, User::query()->where('email', 'b@example.test')->count());
    }

    public function test_hospital_staff_cannot_manage_accounts(): void
    {
        $staff = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $headers = $this->as($staff);

        $this->getJson('/api/v1/users', $headers)->assertForbidden();
        $this->postJson('/api/v1/users', $this->newStaff($this->rsA), $headers)->assertForbidden();
    }

    public function test_only_staff_roles_can_be_given_and_never_without_a_facility(): void
    {
        $global = $this->account(Role::ADMIN, null);
        $headers = $this->as($global);

        $this->postJson('/api/v1/users', $this->newStaff($this->rsA, 'donor'), $headers)->assertStatus(422);
        $this->postJson('/api/v1/users', ['facility_id' => null] + $this->newStaff($this->rsA, 'admin'), ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertStatus(422);
        $this->assertSame(0, User::query()->where('email', 'baru@example.test')->count());
    }

    public function test_an_inactive_facility_cannot_receive_staff(): void
    {
        $global = $this->account(Role::ADMIN, null);
        $this->rsB->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/users', $this->newStaff($this->rsB), $this->as($global))->assertStatus(422);
    }

    // ---------------------------------------------------------------- assign

    public function test_a_role_change_revokes_every_session_and_is_audited(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $target = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $target->createToken('laptop');
        $target->createToken('ponsel');

        $this->putJson("/api/v1/users/{$target->public_id}/assignment", ['facility_id' => $this->rsA->public_id, 'role' => 'admin'], $this->as($admin))
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->assertSame('admin', $this->roleIn($target, $this->rsA->id));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());

        $audit = DB::table('audit_logs')->where('auditable_id', $target->id)->where('action', 'role_assigned')->sole();
        $changes = json_decode((string) $audit->changes, true);
        $this->assertSame($admin->id, $audit->actor_id);
        // assertEquals, bukan assertSame: jsonb menata ulang urutan key objek.
        $this->assertEquals(['facility_id' => $this->rsA->public_id, 'role' => 'hospital_staff'], $changes['before']);
        $this->assertEquals(['facility_id' => $this->rsA->public_id, 'role' => 'admin'], $changes['after']);
    }

    public function test_an_unchanged_assignment_revokes_nothing(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $target = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $target->createToken('laptop');

        $this->putJson("/api/v1/users/{$target->public_id}/assignment", ['facility_id' => $this->rsA->public_id, 'role' => 'hospital_staff'], $this->as($admin))
            ->assertOk();

        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'role_assigned')->count());
    }

    public function test_moving_staff_clears_the_role_in_the_old_facility(): void
    {
        $global = $this->account(Role::ADMIN, null);
        $target = $this->account(Role::HOSPITAL_STAFF, $this->rsA);

        $this->putJson("/api/v1/users/{$target->public_id}/assignment", ['facility_id' => $this->rsB->public_id, 'role' => 'hospital_staff'], $this->as($global))
            ->assertOk()
            ->assertJsonPath('data.facility_id', $this->rsB->public_id);

        $this->assertSame($this->rsB->id, $target->refresh()->facilityId());
        $this->assertSame('hospital_staff', $this->roleIn($target, $this->rsB->id));
        // Tanpa pembersihan, akun ini diam-diam tetap petugas di RS A.
        $this->assertNull($this->roleIn($target, $this->rsA->id));
    }

    public function test_a_facility_admin_cannot_move_staff_out_or_touch_another_facility(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $mine = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $theirs = $this->account(Role::HOSPITAL_STAFF, $this->rsB);
        $headers = $this->as($admin);

        $this->putJson("/api/v1/users/{$mine->public_id}/assignment", ['facility_id' => $this->rsB->public_id, 'role' => 'hospital_staff'], $headers)
            ->assertForbidden();
        $this->putJson("/api/v1/users/{$theirs->public_id}/assignment", ['facility_id' => $this->rsB->public_id, 'role' => 'admin'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertForbidden();
        $this->getJson("/api/v1/users/{$theirs->public_id}", $headers)->assertForbidden();

        $this->assertSame($this->rsA->id, $mine->refresh()->facilityId());
        $this->assertSame('hospital_staff', $this->roleIn($theirs, $this->rsB->id));
    }

    public function test_nobody_changes_or_deactivates_their_own_account(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $headers = $this->as($admin);

        $this->putJson("/api/v1/users/{$admin->public_id}/assignment", ['facility_id' => $this->rsA->public_id, 'role' => 'hospital_staff'], $headers)
            ->assertForbidden();
        $this->patchJson("/api/v1/users/{$admin->public_id}/deactivate", [], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertForbidden();

        $this->assertTrue((bool) $admin->refresh()->is_active);
        $this->assertSame('admin', $this->roleIn($admin, $this->rsA->id));
    }

    public function test_donor_and_global_operator_accounts_are_out_of_reach(): void
    {
        $global = $this->account(Role::ADMIN, null);
        $donor = $this->account(Role::DONOR, null);
        $otherGlobal = $this->account(Role::ADMIN, null);
        $headers = $this->as($global);

        $this->getJson("/api/v1/users/{$donor->public_id}", $headers)->assertForbidden();
        $this->putJson("/api/v1/users/{$otherGlobal->public_id}/assignment", ['facility_id' => $this->rsA->public_id, 'role' => 'hospital_staff'], $headers)
            ->assertForbidden();

        $this->assertNull($otherGlobal->refresh()->facilityId());
    }

    // ------------------------------------------------------------ deactivate

    public function test_deactivating_keeps_the_row_and_kills_sessions_and_devices(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $target = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $target->createToken('laptop');
        $device = new DeviceToken(['platform' => 'android']);
        $device->forceFill(['public_id' => (string) Str::uuid(), 'user_id' => $target->id, 'token' => 'fcm-x', 'last_seen_at' => now()])->save();

        $this->patchJson("/api/v1/users/{$target->public_id}/deactivate", [], $this->as($admin))
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $target->refresh();
        $this->assertFalse((bool) $target->is_active);
        $this->assertNull($target->deleted_at);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
        $this->assertNotNull($device->refresh()->revoked_at);
    }

    public function test_reactivating_restores_access_without_any_session(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $target = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $target->forceFill(['is_active' => false])->save();

        $this->postJson("/api/v1/users/{$target->public_id}/activate", [], $this->as($admin))
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $target->id)->count());
    }

    // ----------------------------------------------------------------- index

    public function test_a_facility_admin_lists_only_their_facility_and_never_donors(): void
    {
        $admin = $this->account(Role::ADMIN, $this->rsA);
        $mine = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $this->account(Role::HOSPITAL_STAFF, $this->rsB);
        $this->account(Role::DONOR, null);

        /** @var list<array<string, mixed>> $data */
        $data = $this->getJson('/api/v1/users', $this->as($admin))->assertOk()->json('data');
        $ids = array_column($data, 'id');
        sort($ids);
        $expected = [$admin->public_id, $mine->public_id];
        sort($expected);

        // Sama persis: admin RS A tidak melihat satu baris pun dari fasilitas lain.
        $this->assertSame($expected, $ids);
    }

    public function test_a_global_operator_lists_staff_of_every_facility_with_their_roles(): void
    {
        $global = $this->account(Role::ADMIN, null);
        $a = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        $b = $this->account(Role::ADMIN, $this->rsB);
        $donor = $this->account(Role::DONOR, null);

        /** @var list<array<string, mixed>> $data */
        $data = $this->getJson('/api/v1/users?per_page=100', $this->as($global))->assertOk()->json('data');
        $rows = array_column($data, null, 'id');

        // Keanggotaan, bukan jumlah: test lain (HardeningTest, tanpa RefreshDatabase)
        // meninggalkan akun staf ter-commit di DB test yang sama.
        $this->assertArrayHasKey($a->public_id, $rows);
        $this->assertArrayHasKey($b->public_id, $rows);
        $this->assertArrayNotHasKey($donor->public_id, $rows);
        $this->assertArrayNotHasKey($global->public_id, $rows);
        $this->assertSame('hospital_staff', $rows[$a->public_id]['role']);
        $this->assertSame('admin', $rows[$b->public_id]['role']);
        $this->assertSame($this->rsB->public_id, $rows[$b->public_id]['facility_id']);
        $this->assertArrayNotHasKey('password', $rows[$a->public_id]);
    }

    // ------------------------------------------------------------------ team

    public function test_writing_a_role_restores_the_callers_permission_team(): void
    {
        $target = $this->account(Role::HOSPITAL_STAFF, $this->rsA);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::GLOBAL_SCOPE);

        app(StaffRoles::class)->set($target, Role::ADMIN);

        $this->assertSame(FacilityScope::GLOBAL_SCOPE, app(PermissionRegistrar::class)->getPermissionsTeamId());
    }
}
