<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\User;
use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_each_role_has_exactly_the_permissions_its_enum_declares(): void
    {
        foreach (RoleEnum::cases() as $roleCase) {
            $role = Role::findByName($roleCase->value, 'web');

            $expected = array_map(
                static fn (PermissionEnum $permission): string => $permission->value,
                $roleCase->permissions()
            );

            $actual = $role->permissions()->pluck('name')->all();

            sort($expected);
            sort($actual);

            $this->assertSame($expected, $actual);
        }
    }

    public function test_an_admin_holds_every_permission_case(): void
    {
        $role = Role::findByName(RoleEnum::ADMIN->value, 'web');

        $expected = array_map(
            static fn (PermissionEnum $permission): string => $permission->value,
            PermissionEnum::cases()
        );

        $actual = $role->permissions()->pluck('name')->all();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_a_donor_cannot_release_inventory(): void
    {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::GLOBAL_SCOPE);
        $user->assignRole(RoleEnum::DONOR->value);

        $this->assertFalse($user->can(PermissionEnum::INVENTORY_RELEASE->value));
    }

    public function test_seeding_twice_does_not_duplicate_roles_or_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(3, Role::count());
        $this->assertSame(count(PermissionEnum::cases()), Permission::count());
    }

    public function test_a_registered_donor_receives_the_donor_role_in_the_global_scope(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Citra Wulandari',
            'email' => 'citra@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertOk();

        $user = User::where('email', 'citra@example.com')->first();
        $role = Role::findByName(RoleEnum::DONOR->value, 'web');

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $role->id,
            'model_id' => $user->id,
            'model_type' => $user->getMorphClass(),
            'facility_id' => FacilityScope::GLOBAL_SCOPE,
        ]);
    }
}
