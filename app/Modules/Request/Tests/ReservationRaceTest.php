<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
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
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lomba SUNGGUHAN, bukan simulasi logika: pemenang memesan lewat service asli di
 * koneksi database kedua dan menahan transaksinya; penantang adalah PROSES PHP
 * TERPISAH yang memanggil service yang sama. Test membuktikan (1) penantang
 * benar-benar MENUNGGU kunci baris unit -- dilihat dari pg_stat_activity -- dan
 * (2) setelah pemenang commit, penantang ditolak 409 yang bisa dibaca, dan
 * status unit serta baris alokasi sepakat.
 *
 * SENGAJA tanpa RefreshDatabase: proses lain tidak bisa melihat data di dalam
 * transaksi test yang belum di-commit. Data di-commit sungguhan dan seluruh
 * tabel dikosongkan lagi di tearDown.
 */
final class ReservationRaceTest extends TestCase
{
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$migrated) {
            $this->artisan('migrate')->assertSuccessful();
            self::$migrated = true;
        }

        $this->truncateEverything();
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        config(['database.connections.race' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::connection('race')->rollBack(0);
        DB::purge('race');
        $this->truncateEverything();

        parent::tearDown();
    }

    private function truncateEverything(): void
    {
        // Tabel milik ekstensi (spatial_ref_sys dari PostGIS) DIKECUALIKAN: migrate:fresh
        // tidak pernah menyentuhnya, jadi sekali dikosongkan ia kosong selamanya dan
        // setiap query SRID 4326 di test lain gagal ("Cannot find SRID").
        $tables = collect(DB::select(<<<'SQL'
            SELECT t.tablename FROM pg_tables t
            WHERE t.schemaname = 'public'
              AND t.tablename <> 'migrations'
              AND NOT EXISTS (
                  SELECT 1 FROM pg_depend d
                  WHERE d.objid = ('public.' || quote_ident(t.tablename))::regclass
                    AND d.deptype = 'e'
              )
        SQL))
            ->map(fn (object $row): string => '"'.$row->tablename.'"')
            ->implode(', ');

        DB::statement("TRUNCATE {$tables} RESTART IDENTITY CASCADE");
    }

    private function allocatedRequest(): BloodRequest
    {
        $facility = Facility::factory()->create();

        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells', 'facility_id' => $facility->id]);
        $unit->forceFill([
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'collected_at' => now()->subDays(30),
            'expires_at' => now()->addDays(10),
            'status' => 'released',
            'released_at' => now()->subDays(29),
        ])->save();

        $request = app(CreateBloodRequest::class)->handle(
            $facility->id,
            User::factory()->create(['facility_id' => $facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            1,
            RequestUrgency::URGENT,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );

        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);

        return $request;
    }

    /** Pemenang: service asli di koneksi kedua, transaksinya DITAHAN terbuka. */
    private function winnerHoldsReservation(BloodRequest $request): void
    {
        DB::connection('race')->beginTransaction();

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('race');

        try {
            app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id));
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /** Penantang: proses PHP terpisah yang memanggil service yang sama. */
    private function challenger(BloodRequest $request): Process
    {
        // php -r dengan kernel console, bukan artisan tinker: tinker menambah puluhan detik boot.
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' try { app(\App\Modules\Request\Application\ReserveAllocatedUnits::class)->handle(\App\Models\BloodRequest::query()->findOrFail('.$request->id.')); echo "RESULT:WON"; }'
            .' catch (\App\Shared\Exceptions\DomainException $e) { echo "RESULT:".$e->errorCode()->value; }'
            .' catch (\Throwable $e) { echo "RESULT:THROWABLE:".get_class($e).":".$e->getMessage(); }';

        $process = new Process(
            ['php', '-r', $code],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database')],
            timeout: 90,
        );
        $process->start();

        return $process;
    }

    private function waitUntilBlockedOnALock(Process $process): void
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'",
            );

            if ($waiting > 0) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail('The challenger finished without ever waiting for the lock: '.$process->getOutput().$process->getErrorOutput());
            }

            usleep(200_000);
        }

        $process->stop();
        $this->fail('The challenger never blocked on the unit row lock.');
    }

    public function test_two_concurrent_reservations_of_the_same_unit_leave_exactly_one_winner(): void
    {
        $request = $this->allocatedRequest();

        $this->winnerHoldsReservation($request);
        $challenger = $this->challenger($request);

        // (1) Kuncinya terpasang: penantang tertahan di baris unit selama pemenang belum commit.
        $this->waitUntilBlockedOnALock($challenger);
        $this->assertTrue($challenger->isRunning());

        DB::connection('race')->commit();
        $challenger->wait();

        // (2) Penantang kalah dengan 409 yang bisa dibaca klien, bukan error database mentah.
        $this->assertStringContainsString('RESULT:BLOOD_BATCH_TRANSITION_REJECTED', $challenger->getOutput(), $challenger->getErrorOutput());

        // (3) Status unit dan baris alokasi sepakat: tepat satu reservasi.
        $this->assertSame('reserved', DB::table('blood_batches')->value('status'));
        $this->assertSame(1, DB::table('blood_request_allocations')->whereNull('released_at')->whereNotNull('reserved_at')->count());
        $this->assertSame(1, DB::table('blood_batch_events')->where('to_status', 'reserved')->count());
    }

    public function test_when_the_winner_rolls_back_the_challenger_reserves_instead(): void
    {
        $request = $this->allocatedRequest();

        $this->winnerHoldsReservation($request);
        $challenger = $this->challenger($request);
        $this->waitUntilBlockedOnALock($challenger);

        DB::connection('race')->rollBack();
        $challenger->wait();

        $this->assertStringContainsString('RESULT:WON', $challenger->getOutput(), $challenger->getErrorOutput());
        $this->assertSame('reserved', DB::table('blood_batches')->value('status'));
        $this->assertSame(1, DB::table('blood_request_allocations')->whereNotNull('reserved_at')->count());
    }
}
