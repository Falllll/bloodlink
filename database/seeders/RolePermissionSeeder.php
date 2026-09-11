<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Permission as PermissionEnum;
use App\Modules\Identity\Domain\Role as RoleEnum;
use App\Shared\Auth\FacilityScope;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::GLOBAL_SCOPE);

        foreach (PermissionEnum::cases() as $case) {
            Permission::firstOrCreate(['name' => $case->value, 'guard_name' => 'web']);
        }

        foreach (RoleEnum::cases() as $roleCase) {
            $role = Role::firstOrCreate(['name' => $roleCase->value, 'guard_name' => 'web']);

            $role->syncPermissions(
                array_map(static fn (PermissionEnum $permission): string => $permission->value, $roleCase->permissions())
            );
        }
    }
}
