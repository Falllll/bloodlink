<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role;
use App\Shared\Auth\FacilityScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class DevUserSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $facility = Facility::query()->where('type', 'hospital')->first();

        if (! $facility) {
            throw new \RuntimeException(
                'DevUserSeeder butuh fasilitas bertipe hospital. Jalankan DevDataSeeder lebih dulu.'
            );
        }

        foreach ($this->accounts($facility->id) as $account) {
            $this->upsertUser($account['role'], $account['email'], $account['facilityId']);
        }
    }

    /** @return list<array{role: string, email: string, facilityId: ?int}> */
    private function accounts(int $facilityId): array
    {
        return [
            ['role' => Role::ADMIN->value, 'email' => 'admin@bloodlink.test', 'facilityId' => null],
            ['role' => Role::ADMIN->value, 'email' => 'admin-rs@bloodlink.test', 'facilityId' => $facilityId],
            ['role' => Role::HOSPITAL_STAFF->value, 'email' => 'staff@bloodlink.test', 'facilityId' => $facilityId],
            ['role' => Role::DONOR->value, 'email' => 'donor@bloodlink.test', 'facilityId' => $facilityId],
        ];
    }

    private function upsertUser(string $role, string $email, ?int $facilityId): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->name = Str::headline(explode('@', $email)[0]);
        $user->password = Hash::make((string) config('dev.seed_password'));

        $user->forceFill([
            'public_id' => $user->public_id ?? (string) Str::uuid(),
            'facility_id' => $facilityId,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        $user->save();

        app(PermissionRegistrar::class)->setPermissionsTeamId(
            FacilityScope::of($facilityId)
        );

        $user->syncRoles([$role]);
    }
}
