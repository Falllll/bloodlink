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
use App\Modules\Request\Application\ReserveAllocatedUnits;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservationTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        // Presisi detik, sama dengan kolom timestamp: batas "tepat now()" tidak goyah.
        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    private function unit(): BloodBatch
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

        return $unit;
    }

    private function request(): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            1,
            RequestUrgency::ROUTINE,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );
    }

    /** Unit yang dipesan dengan batas waktu tertentu. */
    private function reservedUnit(Carbon $expiresAt): BloodBatch
    {
        $unit = $this->unit();
        $request = $this->request();
        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);
        app(ReserveAllocatedUnits::class)->handle($request);

        BloodRequestAllocation::query()->where('blood_batch_id', $unit->id)->sole()
            ->forceFill(['reservation_expires_at' => $expiresAt])->save();

        return $unit;
    }

    private function statusOf(BloodBatch $unit): string
    {
        return (string) DB::table('blood_batches')->where('id', $unit->id)->value('status');
    }

    private function reservedAt(BloodBatch $unit): mixed
    {
        return DB::table('blood_request_allocations')->where('blood_batch_id', $unit->id)->whereNull('released_at')->value('reserved_at');
    }

    public function test_an_expired_reservation_returns_the_unit_to_the_pool(): void
    {
        $unit = $this->reservedUnit(now()->subHour());

        $this->artisan('request:expire-reservations', ['--force' => true])
            ->expectsOutputToContain('Melepas 1 reservasi')
            ->assertSuccessful();

        $this->assertSame('released', $this->statusOf($unit));
        $this->assertNull($this->reservedAt($unit));
        $this->assertSame('reservation timeout', DB::table('blood_request_allocations')->where('blood_batch_id', $unit->id)->value('reservation_release_reason'));
    }

    public function test_a_reservation_expiring_exactly_now_is_not_yet_expired(): void
    {
        $exactlyNow = $this->reservedUnit(now());
        $oneSecondAgo = $this->reservedUnit(now()->subSecond());

        $this->artisan('request:expire-reservations', ['--force' => true])->assertSuccessful();

        $this->assertSame('reserved', $this->statusOf($exactlyNow));
        $this->assertSame('released', $this->statusOf($oneSecondAgo));
    }

    public function test_a_dry_run_releases_nothing(): void
    {
        $unit = $this->reservedUnit(now()->subHour());
        $allocation = BloodRequestAllocation::query()->where('blood_batch_id', $unit->id)->sole();

        $this->artisan('request:expire-reservations')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain($allocation->public_id)
            ->assertSuccessful();

        $this->assertSame('reserved', $this->statusOf($unit));
        $this->assertNotNull($this->reservedAt($unit));
    }

    public function test_running_twice_releases_nothing_the_second_time(): void
    {
        $this->reservedUnit(now()->subHour());
        $this->reservedUnit(now()->subMinutes(5));

        $this->artisan('request:expire-reservations', ['--force' => true])->expectsOutputToContain('Melepas 2 reservasi')->assertSuccessful();
        $this->artisan('request:expire-reservations', ['--force' => true])->expectsOutputToContain('Melepas 0 reservasi')->assertSuccessful();
    }

    public function test_an_unexpired_reservation_is_left_alone(): void
    {
        $unit = $this->reservedUnit(now()->addHours(3));

        $this->artisan('request:expire-reservations', ['--force' => true])->assertSuccessful();

        $this->assertSame('reserved', $this->statusOf($unit));
        $this->assertNotNull($this->reservedAt($unit));
    }

    public function test_a_released_allocation_is_never_touched(): void
    {
        $unit = $this->unit();
        $request = $this->request();
        [$allocation] = app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);
        app(AllocateUnitsFefo::class)->release($allocation, 'Batal.');
        $updatedAt = $allocation->fresh()?->updated_at;

        $this->artisan('request:expire-reservations', ['--force' => true])->expectsOutputToContain('Melepas 0 reservasi')->assertSuccessful();

        $this->assertEquals($updatedAt, $allocation->fresh()?->updated_at);
        $this->assertSame('released', $this->statusOf($unit));
    }

    public function test_an_issued_unit_keeps_its_status_when_its_reservation_times_out(): void
    {
        $unit = $this->reservedUnit(now()->subHour());
        DB::table('blood_batches')->where('id', $unit->id)->update(['status' => 'issued']);

        $this->artisan('request:expire-reservations', ['--force' => true])->assertSuccessful();

        $this->assertSame('issued', $this->statusOf($unit));
        $this->assertNull($this->reservedAt($unit));
    }

    public function test_the_freed_unit_becomes_allocatable_again(): void
    {
        $unit = $this->reservedUnit(now()->subHour());
        // Alokasi lama dilepas pemiliknya setelah reservasinya habis; permintaan baru datang.
        $this->artisan('request:expire-reservations', ['--force' => true])->assertSuccessful();
        app(AllocateUnitsFefo::class)->release(BloodRequestAllocation::query()->where('blood_batch_id', $unit->id)->sole(), 'Operasi batal.');

        $next = $this->request();
        $allocations = app(AllocateUnitsFefo::class)->handle($next, User::factory()->create()->id);

        $this->assertCount(1, $allocations);
        $this->assertSame($unit->id, $allocations[0]->blood_batch_id);
    }
}
