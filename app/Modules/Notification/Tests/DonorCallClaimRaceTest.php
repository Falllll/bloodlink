<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Matching\DonorCallGuard;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lomba SUNGGUHAN atas penjaga panggilan donor (kartu 480), preseden
 * ReservationRaceTest: pemenang mengklaim lewat service asli di koneksi kedua dan
 * MENAHAN transaksinya; penantang adalah PROSES PHP TERPISAH yang mengklaim donor
 * yang sama untuk permintaan yang sama. Tepat satu boleh mendapatkannya.
 *
 * Test ini SENGAJA tidak mensyaratkan penantang tertahan kunci SEBELUM memeriksa
 * hasil: tanpa unique index, penantang tidak tertahan -- ia langsung "menang" juga,
 * dan test harus merah karena DUA klaim, bukan karena alasan lain.
 *
 * SENGAJA tanpa RefreshDatabase: proses lain tidak bisa melihat transaksi test yang
 * belum di-commit. Data di-commit sungguhan dan dikosongkan lagi di tearDown.
 */
final class DonorCallClaimRaceTest extends TestCase
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

    /** Baris permintaan minimal lewat tabel: modul Notification tidak memanggil service Request. */
    private function request(): int
    {
        $facility = Facility::factory()->create();

        return (int) DB::table('blood_requests')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'facility_id' => $facility->id,
            'patient_reference' => 'RM-'.Str::random(6),
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'quantity' => 1,
            'urgency' => 'emergency',
            'status' => 'open',
            'needed_by' => now()->addDay(),
            'requested_by' => User::factory()->create(['facility_id' => $facility->id])->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Pemenang: service asli di koneksi kedua, transaksinya DITAHAN terbuka.
     *
     * @return list<int>
     */
    private function winnerHoldsClaim(int $requestId, int $donorId): array
    {
        DB::connection('race')->beginTransaction();

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('race');

        try {
            return app(DonorCallGuard::class)->claim($requestId, [$donorId], 5000, new DateTimeImmutable);
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /** Penantang: proses PHP terpisah yang memanggil service yang sama. */
    private function challenger(int $requestId, int $donorId): Process
    {
        // php -r dengan kernel console, bukan artisan tinker: tinker menambah puluhan detik boot.
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' try { echo "RESULT:".json_encode(app(\App\Shared\Matching\DonorCallGuard::class)->claim('.$requestId.', ['.$donorId.'], 15000, new DateTimeImmutable)); }'
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

    public function test_two_processes_claiming_the_same_donor_for_the_same_request_leave_exactly_one_claim(): void
    {
        $request = $this->request();
        $donorId = (int) Donor::factory()->create()->id;

        $winner = $this->winnerHoldsClaim($request, $donorId);
        $challenger = $this->challenger($request, $donorId);
        $blocked = $this->waitUntilBlockedOrFinished($challenger);

        DB::connection('race')->commit();
        $challenger->wait();

        $output = $challenger->getOutput();
        $this->assertStringStartsWith('RESULT:', $output, $challenger->getErrorOutput());
        $this->assertStringNotContainsString('THROWABLE', $output);

        // Inti kartu: tepat satu klaim, dan tepat satu pihak yang mendapatkannya.
        $this->assertSame(1, DB::table('donor_call_claims')->where('blood_request_id', $request)->where('donor_id', $donorId)->count());
        $this->assertSame([$donorId], $winner);
        $this->assertSame('RESULT:[]', $output);
        // Jenjang pemenang yang tersimpan, bukan jenjang penantang.
        $this->assertSame(5000, (int) DB::table('donor_call_claims')->value('radius_meters'));

        // Dan penjaganya memang database: penantang MENUNGGU index, bukan kebetulan kalah cepat.
        $this->assertTrue($blocked, 'The challenger never waited on the unique index.');
    }

    public function test_when_the_winner_rolls_back_the_challenger_claims_instead(): void
    {
        $request = $this->request();
        $donorId = (int) Donor::factory()->create()->id;

        $this->winnerHoldsClaim($request, $donorId);
        $challenger = $this->challenger($request, $donorId);
        $this->assertTrue($this->waitUntilBlockedOrFinished($challenger));

        DB::connection('race')->rollBack();
        $challenger->wait();

        $this->assertSame("RESULT:[{$donorId}]", $challenger->getOutput(), $challenger->getErrorOutput());
        $this->assertSame(15000, (int) DB::table('donor_call_claims')->value('radius_meters'));
    }
}
