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
use App\Modules\Request\Application\ReturnAllocatedUnit;
use App\Modules\Request\Domain\ReleaseReason;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Closure;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Lomba reservasi yang belum diuji ReservationRaceTest (kartu 680), mekanismenya sama:
 * pemegang di koneksi database kedua dengan transaksi DITAHAN, penantang PROSES PHP TERPISAH.
 *
 * SENGAJA tanpa RefreshDatabase: proses lain tidak bisa melihat data di dalam transaksi
 * test yang belum di-commit. Data di-commit sungguhan dan dikosongkan lagi di tearDown.
 */
final class ReservationRaceEdgeTest extends TestCase
{
    private const string RESERVE = 'app(\App\Modules\Request\Application\ReserveAllocatedUnits::class)->handle(\App\Models\BloodRequest::query()->findOrFail(%d));';

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
        // spatial_ref_sys milik PostGIS dikecualikan: sekali kosong, SRID 4326 gagal selamanya.
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

    /** Hari kedaluwarsa dari sekarang; unit dibuat berurutan, jadi id-nya menaik. */
    private function unitAt(Facility $facility, int $expiresInDays = 10): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells', 'facility_id' => $facility->id]);
        $unit->forceFill([
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'collected_at' => now()->subDays(30),
            'expires_at' => now()->addDays($expiresInDays),
            'status' => 'released',
            'released_at' => now()->subDays(29),
        ])->save();

        return $unit;
    }

    private function requestAt(Facility $facility, int $quantity = 1): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $facility->id,
            User::factory()->create(['facility_id' => $facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            $quantity,
            RequestUrgency::URGENT,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );
    }

    private function allocatedRequest(): BloodRequest
    {
        $facility = Facility::factory()->create();
        $this->unitAt($facility);
        $request = $this->requestAt($facility);
        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);

        return $request;
    }

    /**
     * Dua unit: yang ber-id LEBIH BESAR kedaluwarsa lebih dulu, jadi FEFO menulis alokasinya
     * lebih dulu. Tanpa orderBy('blood_batch_id') baris terbaca dengan id unit menurun --
     * tanpa ini, membuang orderBy tidak mengubah urutan kunci apa pun.
     *
     * @return array{BloodRequest, int, int} permintaan, id unit lebih kecil, id unit lebih besar
     */
    private function twoUnitRequest(): array
    {
        $facility = Facility::factory()->create();
        $lower = $this->unitAt($facility, 20);
        $higher = $this->unitAt($facility, 5);
        $request = $this->requestAt($facility, 2);
        app(AllocateUnitsFefo::class)->handle($request, User::factory()->create()->id);

        $this->assertSame(
            [$higher->id, $lower->id],
            BloodRequestAllocation::query()->where('blood_request_id', $request->id)->orderBy('id')->pluck('blood_batch_id')->map(fn ($id): int => (int) $id)->all(),
        );

        return [$request, $lower->id, $higher->id];
    }

    /** Kode asli di koneksi kedua, transaksinya DITAHAN terbuka. */
    private function hold(Closure $work): void
    {
        DB::connection('race')->beginTransaction();

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('race');

        try {
            $work();
        } finally {
            DB::setDefaultConnection($default);
        }
    }

    /**
     * Proses PHP terpisah. RESULT:WON, atau RESULT:KODE|KELAS|PESAN untuk penolakan domain.
     *
     * @param  array<string, string>  $env
     */
    private function challenger(string $call, array $env = []): Process
    {
        // php -r dengan kernel console, bukan artisan tinker: tinker menambah puluhan detik boot.
        $code = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
            .' try { $result = '.rtrim($call, ';').'; echo "RESULT:WON:".(is_array($result) ? count($result) : "-"); }'
            .' catch (\App\Shared\Exceptions\DomainException $e) { echo "RESULT:".$e->errorCode()->value."|".get_class($e)."|".$e->getMessage(); }'
            .' catch (\Throwable $e) { echo "RESULT:THROWABLE:".get_class($e).":".$e->getMessage(); }';

        $process = new Process(
            ['php', '-r', $code],
            base_path(),
            ['APP_ENV' => 'testing', 'DB_DATABASE' => (string) config('database.connections.pgsql.database')] + $env,
            timeout: 90,
        );
        $process->start();

        return $process;
    }

    private function reserveChallenger(BloodRequest $request): Process
    {
        return $this->challenger(sprintf(self::RESERVE, $request->id));
    }

    private function waitUntilBlocked(int $howMany = 1): void
    {
        $deadline = microtime(true) + 60;

        while (microtime(true) < $deadline) {
            $waiting = (int) DB::scalar(
                "SELECT count(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'",
            );

            if ($waiting >= $howMany) {
                return;
            }

            usleep(200_000);
        }

        $this->fail("Expected {$howMany} challenger(s) blocked on a lock; they never were.");
    }

    private function finish(Process $process): string
    {
        $process->wait();

        return $process->getOutput().$process->getErrorOutput();
    }

    private function unitStatus(int $unitId): string
    {
        return (string) DB::table('blood_batches')->where('id', $unitId)->value('status');
    }

    private function allocationOf(BloodRequest $request): BloodRequestAllocation
    {
        return BloodRequestAllocation::query()->where('blood_request_id', $request->id)->sole();
    }

    // Celah 1. Dua permintaan tidak bisa memegang dua unit yang sama (satu alokasi aktif per
    // unit), jadi lawannya pemegang kunci berurutan TERBALIK. Penantang menunggu di unit id
    // terkecil tanpa memegang apa pun, sehingga pemegang tetap bisa mengambil unit satunya.
    public function test_two_units_are_locked_in_id_order_so_a_reverse_holder_cannot_deadlock(): void
    {
        [$request, $lowerId, $higherId] = $this->twoUnitRequest();

        $this->hold(fn () => DB::select('SELECT id FROM blood_batches WHERE id = ? FOR UPDATE', [$lowerId]));
        // Seq scan dipaksa: di tabel kecil planner kebetulan membaca lewat index yang sudah
        // terurut, jadi tanpa orderBy test ini tetap hijau. Dengan seq scan baris terbaca menurut
        // urutan tulis (id unit menurun), dan hanya orderBy yang membalikkannya.
        $challenger = $this->challenger(
            sprintf(self::RESERVE, $request->id),
            ['PGOPTIONS' => '-c enable_indexscan=off -c enable_bitmapscan=off -c enable_indexonlyscan=off'],
        );
        $this->waitUntilBlocked();

        // Kalau penantang sudah memegang unit ini, baris ini menutup lingkaran: deadlock.
        DB::connection('race')->select('SELECT id FROM blood_batches WHERE id = ? FOR UPDATE', [$higherId]);
        DB::connection('race')->commit();

        $output = $this->finish($challenger);
        $this->assertStringContainsString('RESULT:WON:2', $output);
        $this->assertStringNotContainsString('deadlock', strtolower($output));
        $this->assertSame('reserved', $this->unitStatus($lowerId));
        $this->assertSame('reserved', $this->unitStatus($higherId));
    }

    // Celah 2. Selama pemesanan belum commit, alokasinya masih terbaca belum dipesan: pelepasan
    // tidak menunggu dan idempoten. Yang dijaga: status unit dan alokasi tetap sepakat.
    public function test_a_release_during_an_open_reservation_leaves_the_reservation_standing(): void
    {
        $request = $this->allocatedRequest();
        $unitId = (int) $this->allocationOf($request)->blood_batch_id;

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        $output = $this->finish($this->challenger(
            'app(\App\Modules\Request\Application\ReserveAllocatedUnits::class)->release(\App\Models\BloodRequestAllocation::query()->where("blood_request_id", '.$request->id.')->sole(), "lepas")',
        ));
        $this->assertStringContainsString('RESULT:WON', $output);
        DB::connection('race')->commit();

        $this->assertSame('reserved', $this->unitStatus($unitId));
        $this->assertNotNull($this->allocationOf($request)->reserved_at);
    }

    public function test_a_reservation_during_an_open_release_is_rejected_and_the_release_stands(): void
    {
        $request = $this->allocatedRequest();
        app(ReserveAllocatedUnits::class)->handle($request);
        $allocation = $this->allocationOf($request);

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->release(BloodRequestAllocation::query()->findOrFail($allocation->id), 'lepas'));
        $output = $this->finish($this->reserveChallenger($request));
        $this->assertStringContainsString('ReservationRejected', $output);
        DB::connection('race')->commit();

        $this->assertSame('released', $this->unitStatus((int) $allocation->blood_batch_id));
        $this->assertNull($this->allocationOf($request)->reserved_at);
    }

    /**
     * Celah 3. CACAT YANG DIKETAHUI, dipaku supaya terlihat.
     * YANG BENAR: reserved_at dan reservation_expires_at kosong sesudah terbit. Hari ini
     * IssueAllocatedUnit menulis lewat model yang dibaca SEBELUM menunggu kunci: di model itu
     * keduanya sudah null, jadi tidak ikut tersimpan. Kantongnya aman (timeout tidak mengubah
     * unit issued), tapi alasan "issued" tertimpa. Saat diperbaiki, test ini merah dan dibalik.
     */
    public function test_known_defect_an_issue_waiting_on_a_reservation_leaves_it_recorded(): void
    {
        $request = $this->allocatedRequest();
        $allocation = $this->allocationOf($request);
        DB::table('crossmatches')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_request_allocation_id' => $allocation->id,
            'performed_by' => User::factory()->create()->id,
            'result' => 'compatible',
            'performed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $issuer = User::factory()->create()->id;

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        $challenger = $this->challenger(
            'app(\App\Modules\Request\Application\IssueAllocatedUnit::class)->handle(\App\Models\BloodRequestAllocation::query()->findOrFail('.$allocation->id.'), \App\Models\User::query()->findOrFail('.$issuer.'))',
        );
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $output = $this->finish($challenger);

        $this->assertStringContainsString('RESULT:WON', $output);
        $this->assertSame('issued', $this->unitStatus((int) $allocation->blood_batch_id));
        $fresh = $this->allocationOf($request);
        $this->assertNotNull($fresh->reserved_at);
        $this->assertNotNull($fresh->reservation_expires_at);
        $this->assertSame('issued', $fresh->reservation_release_reason);

        // Akibatnya saat timeout: kantong tetap issued, tapi catatan alasannya tertimpa.
        DB::table('blood_request_allocations')->where('id', $allocation->id)->update(['reservation_expires_at' => now()->subMinute()]);
        Artisan::call('request:expire-reservations', ['--force' => true]);
        $this->assertSame('issued', $this->unitStatus((int) $allocation->blood_batch_id));
        $this->assertSame('reservation timeout', $this->allocationOf($request)->reservation_release_reason);
    }

    public function test_a_return_waiting_on_a_reservation_undoes_it_completely(): void
    {
        $request = $this->allocatedRequest();
        $allocation = $this->allocationOf($request);

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        $challenger = $this->challenger(
            '\Illuminate\Support\Facades\DB::transaction(fn () => app(\App\Modules\Request\Application\ReturnAllocatedUnit::class)->handle(\App\Models\BloodRequestAllocation::query()->findOrFail('.$allocation->id.'), \App\Modules\Request\Domain\ReleaseReason::REQUEST_CANCELLED))',
        );
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $output = $this->finish($challenger);

        $this->assertStringContainsString('RESULT:WON', $output);
        $this->assertSame('released', $this->unitStatus((int) $allocation->blood_batch_id));
        $fresh = $this->allocationOf($request);
        $this->assertNotNull($fresh->released_at);
        $this->assertNull($fresh->reserved_at);
    }

    /**
     * Celah 3, arah sebaliknya. CACAT YANG DIKETAHUI, dipaku supaya terlihat.
     * YANG BENAR: penantang ditolak, unit tetap released, alokasi tidak dipesan. Hari ini
     * ReserveAllocatedUnits membaca alokasinya SEBELUM menunggu kunci unit dan tidak membaca
     * ulang: unit jadi reserved tanpa alokasi aktif. Tidak ada CHECK yang menolaknya, dan timeout
     * reservasi menyaring released_at, jadi kantong keluar dari stok sampai kedaluwarsa, tanpa
     * satu pun error. Saat diperbaiki di kartunya sendiri, test ini merah dan harus dibalik.
     */
    public function test_known_defect_a_reservation_waiting_on_a_return_strands_the_unit(): void
    {
        $request = $this->allocatedRequest();
        $allocation = $this->allocationOf($request);

        $this->hold(fn () => app(ReturnAllocatedUnit::class)->handle(BloodRequestAllocation::query()->findOrFail($allocation->id), ReleaseReason::REQUEST_CANCELLED));
        $challenger = $this->reserveChallenger($request);
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $this->assertStringContainsString('RESULT:WON', $this->finish($challenger));

        $unitId = (int) $allocation->blood_batch_id;
        $fresh = $this->allocationOf($request);
        $this->assertNotNull($fresh->released_at);
        $this->assertNotNull($fresh->reserved_at);
        $this->assertSame('reserved', $this->unitStatus($unitId));
        $this->assertSame(0, BloodRequestAllocation::query()->where('blood_batch_id', $unitId)->whereNull('released_at')->count());

        // Timeout reservasi tidak menyelamatkannya: unit tetap tertahan.
        DB::table('blood_request_allocations')->where('id', $allocation->id)->update(['reservation_expires_at' => now()->subMinute()]);
        Artisan::call('request:expire-reservations', ['--force' => true]);
        $this->assertSame('reserved', $this->unitStatus($unitId));
    }

    public function test_expiry_waiting_on_a_reservation_still_expires_the_overdue_unit(): void
    {
        $request = $this->allocatedRequest();
        $unitId = (int) $this->allocationOf($request)->blood_batch_id;
        DB::table('blood_batches')->where('id', $unitId)->update(['expires_at' => now()->subHour()]);

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        $challenger = $this->challenger('\Illuminate\Support\Facades\Artisan::call("inventory:expire-check", ["--force" => true])');
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $output = $this->finish($challenger);

        $this->assertStringContainsString('RESULT:WON', $output);
        $this->assertSame('expired', $this->unitStatus($unitId));
    }

    public function test_a_reservation_waiting_on_expiry_is_rejected(): void
    {
        $request = $this->allocatedRequest();
        $unitId = (int) $this->allocationOf($request)->blood_batch_id;
        DB::table('blood_batches')->where('id', $unitId)->update(['expires_at' => now()->subHour()]);

        $this->hold(fn () => Artisan::call('inventory:expire-check', ['--force' => true]));
        $challenger = $this->reserveChallenger($request);
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $output = $this->finish($challenger);

        $this->assertStringContainsString('RESULT:BLOOD_BATCH_TRANSITION_REJECTED|', $output);
        $this->assertSame('expired', $this->unitStatus($unitId));
        $this->assertNull($this->allocationOf($request)->reserved_at);
    }

    // Celah 4. Cabang isEmpty hanya tercapai sesudah pemenang COMMIT: sebelum itu penantang
    // masih membaca alokasinya belum dipesan dan kalah di graf (lihat test tiga penantang).
    public function test_a_challenger_after_the_commit_gets_nothing_to_reserve(): void
    {
        $request = $this->allocatedRequest();

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        DB::connection('race')->commit();

        $output = $this->finish($this->reserveChallenger($request));

        $this->assertStringContainsString('RESULT:BLOOD_BATCH_TRANSITION_REJECTED|', $output);
        $this->assertStringContainsString('ReservationRejected', $output);
        $this->assertStringContainsString('This blood request has no allocated units left to reserve.', $output);
    }

    // Celah 5. Unit PERTAMA sudah terkunci dan terpesan di transaksi penantang saat unit KEDUA
    // ditolak: tidak boleh ada yang tersisa dipesan, sama sekali.
    public function test_a_rejection_on_the_second_unit_leaves_no_unit_reserved(): void
    {
        [$request, $lowerId, $higherId] = $this->twoUnitRequest();

        $this->hold(fn () => DB::table('blood_batches')->where('id', $higherId)->update(['status' => 'discarded', 'discard_reason' => 'kartu 680']));
        $challenger = $this->reserveChallenger($request);
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $output = $this->finish($challenger);

        $this->assertStringContainsString('RESULT:BLOOD_BATCH_TRANSITION_REJECTED|', $output);
        $this->assertSame('released', $this->unitStatus($lowerId));
        $this->assertSame('discarded', $this->unitStatus($higherId));
        $this->assertSame(0, DB::table('blood_batches')->where('status', 'reserved')->count());
        $this->assertSame(0, DB::table('blood_request_allocations')->whereNotNull('reserved_at')->count());
        $this->assertSame(0, DB::table('blood_batch_events')->where('to_status', 'reserved')->count());
    }

    // Celah 6. Lomba ALOKASI sungguhan: penantang tertahan di unique index, lalu menangkap
    // pelanggarannya dan pindah ke kandidat berikutnya. Tanpa index, ia tidak pernah menunggu.
    public function test_concurrent_allocation_gives_a_unit_to_one_request_only(): void
    {
        $facility = Facility::factory()->create();
        $contested = $this->unitAt($facility, 5);
        $spare = $this->unitAt($facility, 20);
        $first = $this->requestAt($facility);
        $second = $this->requestAt($facility);
        $by = User::factory()->create()->id;

        $this->hold(fn () => app(AllocateUnitsFefo::class)->handle(BloodRequest::query()->findOrFail($first->id), $by));
        $challenger = $this->challenger(
            'app(\App\Modules\Request\Application\AllocateUnitsFefo::class)->handle(\App\Models\BloodRequest::query()->findOrFail('.$second->id.'), '.$by.')',
        );
        $this->waitUntilBlocked();
        DB::connection('race')->commit();
        $output = $this->finish($challenger);

        $this->assertStringContainsString('RESULT:WON:1', $output);
        $this->assertSame((int) $contested->id, (int) $this->allocationOf($first)->blood_batch_id);
        $this->assertSame((int) $spare->id, (int) $this->allocationOf($second)->blood_batch_id);
    }

    // Celah 7. Pemegang commit: ketiga penantang kalah, dan yang tercatat tepat satu reservasi.
    public function test_three_challengers_against_a_committed_winner_all_get_a_409(): void
    {
        $request = $this->allocatedRequest();

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        $challengers = [$this->reserveChallenger($request), $this->reserveChallenger($request), $this->reserveChallenger($request)];
        $this->waitUntilBlocked(3);
        DB::connection('race')->commit();

        foreach ($challengers as $challenger) {
            $this->assertStringContainsString('RESULT:BLOOD_BATCH_TRANSITION_REJECTED|', $this->finish($challenger));
        }

        $this->assertSame(1, DB::table('blood_batch_events')->where('to_status', 'reserved')->count());
    }

    // Pemegang batal: lomba sungguhan di antara penantang, tepat satu menang dan sisanya 409.
    public function test_three_challengers_after_a_rollback_leave_exactly_one_winner(): void
    {
        $request = $this->allocatedRequest();

        $this->hold(fn () => app(ReserveAllocatedUnits::class)->handle(BloodRequest::query()->findOrFail($request->id)));
        $challengers = [$this->reserveChallenger($request), $this->reserveChallenger($request), $this->reserveChallenger($request)];
        $this->waitUntilBlocked(3);
        DB::connection('race')->rollBack();

        $outputs = array_map(fn (Process $challenger): string => $this->finish($challenger), $challengers);
        $won = array_filter($outputs, fn (string $output): bool => str_contains($output, 'RESULT:WON'));
        $lost = array_filter($outputs, fn (string $output): bool => str_contains($output, 'RESULT:BLOOD_BATCH_TRANSITION_REJECTED|'));

        $this->assertCount(1, $won, implode("\n", $outputs));
        $this->assertCount(2, $lost, implode("\n", $outputs));
        $this->assertSame('reserved', $this->unitStatus((int) $this->allocationOf($request)->blood_batch_id));
        $this->assertSame(1, DB::table('blood_batch_events')->where('to_status', 'reserved')->count());
    }
}
