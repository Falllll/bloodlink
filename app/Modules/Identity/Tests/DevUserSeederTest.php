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
            'donor@bloodlink.test' => [Role::DONOR, null],
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

    /** DB dev lama: donor pernah di-seed ber-facility_id, dengan peran di team fasilitas itu. */
    public function test_rerunning_it_moves_an_old_donor_out_of_its_facility_without_a_stale_role(): void
    {
        $this->seed(DevUserSeeder::class);
        $donor = User::query()->where('email', 'donor@bloodlink.test')->firstOrFail();
        $facilityId = (int) Facility::query()->value('id');
        $donor->forceFill(['facility_id' => $facilityId])->save();
        DB::table('model_has_roles')->where('model_id', $donor->id)->where('model_type', User::class)->update(['facility_id' => $facilityId]);

        $this->seed(DevUserSeeder::class);

        $this->assertNull($donor->refresh()->facility_id);
        $this->assertSame(
            [FacilityScope::of(null)],
            DB::table('model_has_roles')->where('model_id', $donor->id)->where('model_type', User::class)->pluck('facility_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_it_revives_a_soft_deleted_seed_account(): void
    {
        $this->seed(DevUserSeeder::class);

        $seeded = User::query()->where('email', 'admin@bloodlink.test')->firstOrFail();
        $seeded->delete();

        $this->assertSame(0, User::query()->where('email', 'admin@bloodlink.test')->count());

        $this->seed(DevUserSeeder::class);

        $this->assertSame(1, User::withTrashed()->where('email', 'admin@bloodlink.test')->count());

        $revived = User::query()->where('email', 'admin@bloodlink.test')->firstOrFail();

        $this->assertSame($seeded->id, $revived->id);
        $this->assertNull($revived->deleted_at);
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

        // --force melewati konfirmasi db:seed, supaya yang diuji benar-benar
        // penjaga isProduction() di dalam seeder, bukan pembatalan oleh db:seed.
        $this->artisan('db:seed', ['--class' => DevUserSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame($before, User::query()->count());
    }
}
