<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\Facility;
use App\Models\User;
use App\Modules\Identity\Domain\Role;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DevUserSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DevUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Facility::factory()->state(['type' => 'hospital'])->create();
    }

    public function test_it_creates_one_user_per_role(): void
    {
        $this->seed(DevUserSeeder::class);

        $accounts = [
            'admin@bloodlink.test' => [Role::ADMIN, null],
            'admin-rs@bloodlink.test' => [Role::ADMIN, 'set'],
            'staff@bloodlink.test' => [Role::HOSPITAL_STAFF, 'set'],
            'donor@bloodlink.test' => [Role::DONOR, 'set'],
        ];

        $this->assertSame(4, User::query()->whereIn('email', array_keys($accounts))->count());

        foreach ($accounts as $email => [$role, $hasFacility]) {
            $user = User::query()->where('email', $email)->firstOrFail();

            if ($hasFacility === null) {
                $this->assertNull($user->facility_id);
            } else {
                $this->assertNotNull($user->facility_id);
            }

            app(PermissionRegistrar::class)->setPermissionsTeamId(
                FacilityScope::of($user->facility_id)
            );

            $this->assertTrue($user->hasRole($role->value));
        }
    }

    public function test_running_it_twice_does_not_duplicate_roles(): void
    {
        $this->seed(DevUserSeeder::class);
        $this->seed(DevUserSeeder::class);

        $this->assertSame(4, User::query()->count());

        $user = User::query()->where('email', 'admin@bloodlink.test')->firstOrFail();

        $this->assertSame(
            1,
            DB::table('model_has_roles')->where('model_id', $user->id)->count()
        );
    }

    public function test_the_seeded_admin_can_log_in_and_receives_its_role(): void
    {
        $this->seed(DevUserSeeder::class);

        // admin-rs@bloodlink.test is facility-scoped (facility_id != 0/GLOBAL_SCOPE),
        // so unlike admin@bloodlink.test it cannot pass by coincidentally matching
        // whatever permission team id happens to be ambient before the seeder runs.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin-rs@bloodlink.test',
            'password' => (string) config('dev.seed_password'),
        ], ['Idempotency-Key' => (string) Str::uuid()]);

        $response->assertOk()
            ->assertJsonPath('data.user.role', 'admin');
    }

    public function test_it_does_nothing_when_the_app_is_in_production(): void
    {
        app()['env'] = 'production';

        $before = User::query()->count();

        $this->seed(DevUserSeeder::class);

        $this->assertSame($before, User::query()->count());
    }
}
