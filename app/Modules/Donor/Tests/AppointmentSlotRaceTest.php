<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Donor;
use App\Models\Facility;
use App\Models\FacilityAppointmentSchedule;
use App\Modules\Donor\Application\BookAppointment;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lomba SUNGGUHAN atas kursi terakhir sebuah slot (kartu 503), preseden
 * ReservationRaceTest: pemenang memesan lewat service asli di koneksi kedua dan
 * MENAHAN transaksinya; penantang adalah PROSES PHP TERPISAH yang memesan slot yang
 * sama untuk donor lain. `SELECT COUNT(*)` tanpa kunci akan meloloskan keduanya.
 *
 * Tidak mensyaratkan penantang tertahan SEBELUM memeriksa hasil: tanpa kunci baris
 * ia langsung lolos, dan test harus merah karena DUA pemesanan, bukan karena hal lain.
 *
 * SENGAJA tanpa RefreshDatabase: proses lain tidak bisa melihat transaksi test yang
 * belum di-commit. Data di-commit sungguhan dan dikosongkan lagi di tearDown.
 */
final class AppointmentSlotRaceTest extends TestCase
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
        // Tabel milik ekstensi (spatial_ref_sys dari PostGIS) DIKECUALIKAN: sekali
        // dikosongkan ia kosong selamanya, dan setiap query SRID 4326 di test lain gagal.
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

    /**
     * Satu kursi: setiap hari 08:00-09:00 WIB, slot 60 menit, kapasitas 1.
     *
     * @return array{0: int, 1: int, 2: string} donor pemenang, donor penantang, slot (ISO, jam dinding WIB)
     */
    private function lastSeat(): array
    {
        $facility = Facility::factory()->create(['is_active' => true]);
        $facility->forceFill(['timezone' => 'Asia/Jakarta'])->save();

        foreach (range(1, 7) as $weekday) {
            $row = new FacilityAppointmentSchedule(['weekday' => $weekday, 'opens_minute' => 480, 'closes_minute' => 540, 'slot_minutes' => 60, 'capacity' => 1]);
            $row->forceFill(['facility_id' => $facility->id])->save();
        }

        $slot = Carbon::now('Asia/Jakarta')->addDays(2)->setTime(8, 0)->toIso8601String();
        $winner = (int) Donor::factory()->create(['registered_facility_id' => $facility->id])->id;
        $challenger = (int) Donor::factory()->create(['registered_facility_id' => $facility->id])->id;

        return [$winner, $challenger, $slot];
    }

    /** Pemenang: service asli di koneksi kedua, transaksinya DITAHAN terbuka. */
    private function winnerHoldsTheSeat(int $donorId, string $slot): void
    {
        DB::connection('race')->beginTransaction();

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('race');

        try {
            app(BookAppointment::class)->handle(Donor::query()->findOrFail($donorId), new DateTimeImmutable($slot));
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /** Penantang: proses PHP terpisah yang memanggil service yang sama. */
    private function challenger(int $donorId, string $slot): Process
    {
        // php -r dengan kernel console, bukan artisan tinker: tinker menambah puluhan detik boot.
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' try { app(\App\Modules\Donor\Application\BookAppointment::class)->handle(\App\Models\Donor::query()->findOrFail('.$donorId.'), new DateTimeImmutable("'.$slot.'")); echo "RESULT:WON"; }'
            .' catch (\App\Shared\Exceptions\DomainException $e) { echo "RESULT:".$e->errorCode()->value.":".$e->httpStatus(); }'
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

    /** true bila penantang tertahan kunci; false bila ia selesai tanpa pernah menunggu. */
    private function waitUntilBlockedOrFinished(Process $process): bool
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'",
            );

            if ($waiting > 0) {
                return true;
            }

            if (! $process->isRunning()) {
                return false;
            }

            usleep(200_000);
        }

        $process->stop();
        $this->fail('The challenger neither blocked nor finished within 60 seconds.');
    }

    public function test_two_donors_racing_for_the_last_seat_leave_exactly_one_booking(): void
    {
        [$winner, $challenger, $slot] = $this->lastSeat();

        $this->winnerHoldsTheSeat($winner, $slot);
        $process = $this->challenger($challenger, $slot);
        $blocked = $this->waitUntilBlockedOrFinished($process);

        DB::connection('race')->commit();
        $process->wait();

        // Inti kartu: satu kursi, satu pemesanan -- penantang kalah dengan 409 yang bisa dibaca.
        $this->assertSame(1, DB::table('appointments')->where('status', 'booked')->count());
        $this->assertSame('RESULT:APPOINTMENT_SLOT_UNAVAILABLE:409', $process->getOutput(), $process->getErrorOutput());

        // Dan penjaganya memang database: penantang MENUNGGU kunci baris, bukan kebetulan kalah cepat.
        $this->assertTrue($blocked, 'The challenger never waited on the schedule row lock.');
    }

    public function test_when_the_winner_rolls_back_the_challenger_gets_the_seat(): void
    {
        [$winner, $challenger, $slot] = $this->lastSeat();

        $this->winnerHoldsTheSeat($winner, $slot);
        $process = $this->challenger($challenger, $slot);
        $this->assertTrue($this->waitUntilBlockedOrFinished($process));

        DB::connection('race')->rollBack();
        $process->wait();

        $this->assertSame('RESULT:WON', $process->getOutput(), $process->getErrorOutput());
        $this->assertSame($challenger, (int) DB::table('appointments')->value('donor_id'));
    }
}
