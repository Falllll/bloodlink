<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\BloodBatchTransitionRejected;
use App\Modules\Inventory\Application\TransitionBloodBatch;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\Events\BloodUnitsNearingExpiry;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Dry run adalah default: EXPIRED terminal menurut graf, tidak ada jalan pulang.
 * Scheduler memanggilnya dengan --force.
 *
 * Tanpa visibleTo(): command berjalan tanpa user, dan scope fasilitas yang
 * gagal-tertutup akan membalas nol baris tanpa error.
 */
final class ExpireBloodUnits extends Command
{
    /**
     * Ambang peringatan OPERASIONAL, bukan angka medis: Rujukan Pedoman Medis
     * tidak memuat ambang peringatan apa pun, jadi angka ini pilihan kita.
     */
    private const int NEAR_EXPIRY_DAYS = 7;

    protected $signature = 'inventory:expire-check
        {--force : benar-benar tulis perubahan status}
        {--days= : ambang hari untuk peringatan mendekati kedaluwarsa}';

    protected $description = 'Tandai unit yang sudah kedaluwarsa dan pancarkan sinyal untuk unit yang mendekati kedaluwarsa';

    public function __construct(private readonly TransitionBloodBatch $transition)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $daysOption = $this->option('days');

        if ($daysOption !== null && filter_var($daysOption, FILTER_VALIDATE_INT) === false) {
            $this->error('--days harus bilangan bulat.');

            return self::FAILURE;
        }

        $days = $daysOption === null ? self::NEAR_EXPIRY_DAYS : (int) $daysOption;

        if ($days < 1) {
            $this->error('--days harus minimal 1.');

            return self::FAILURE;
        }

        // Satu titik waktu untuk seluruh jalan: dua query tidak boleh melihat "sekarang" yang berbeda.
        $now = now();

        if (! $force) {
            $this->warn('DRY RUN -- tidak ada yang ditulis. Jalankan dengan --force untuk menandai dan memancarkan sinyal.');
        }

        $expired = $this->expireOverdue($now, $force);
        $facilities = $this->signalNearExpiry($now, $days, $force);

        $this->info($force
            ? "Menandai {$expired} unit kedaluwarsa; sinyal mendekati kedaluwarsa ({$days} hari) untuk {$facilities} fasilitas."
            : "Dry run: {$expired} unit akan ditandai kedaluwarsa; {$facilities} fasilitas akan menerima sinyal mendekati kedaluwarsa ({$days} hari).");

        return self::SUCCESS;
    }

    private function expireOverdue(Carbon $now, bool $force): int
    {
        $count = 0;

        // SENGAJA `<`, bukan `<=`: unit yang kedaluwarsa tepat detik ini belum lewat.
        $this->eachCandidate(
            fn (Builder $query) => $query->where('expires_at', '<', $now),
            function ($units) use ($force, &$count): void {
                foreach ($units as $unit) {
                    /** @var BloodBatch $unit */
                    if (! $unit->status->canTransitionTo(BatchStatus::EXPIRED)) {
                        continue;
                    }

                    if ($force) {
                        try {
                            $this->transition->handle($unit, BatchStatus::EXPIRED);
                        } catch (BloodBatchTransitionRejected $e) {
                            // Status berubah di antara baca dan kunci (mis. baru saja diterbitkan):
                            // lewati unit ini, jangan hentikan seluruh jalan malam.
                            $this->warn("Lewati {$unit->public_id}: {$e->getMessage()}");

                            continue;
                        }
                    } else {
                        $this->line("  akan ditandai: {$unit->public_id} ({$unit->status->value}, kedaluwarsa {$unit->expires_at?->toAtomString()})");
                    }

                    $count++;
                }
            },
        );

        return $count;
    }

    private function signalNearExpiry(Carbon $now, int $days, bool $force): int
    {
        /** @var array<int, list<string>> $byFacility */
        $byFacility = [];

        $this->eachCandidate(
            fn (Builder $query) => $query->whereBetween('expires_at', [$now, $now->copy()->addDays($days)]),
            function ($units) use (&$byFacility): void {
                foreach ($units as $unit) {
                    /** @var BloodBatch $unit */
                    if ($unit->status->canTransitionTo(BatchStatus::EXPIRED)) {
                        $byFacility[$unit->facility_id][] = $unit->public_id;
                    }
                }
            },
        );

        foreach ($byFacility as $facilityId => $publicIds) {
            if (! $force) {
                $this->line('  akan disinyalkan: fasilitas '.$facilityId.', '.count($publicIds).' unit');

                continue;
            }

            event(new BloodUnitsNearingExpiry(
                $facilityId,
                $days,
                $publicIds,
                $now->toDateTimeImmutable()->format(DateTimeInterface::ATOM),
            ));
        }

        return count($byFacility);
    }

    /**
     * Id kandidat diambil dulu lewat index (status, expires_at), baru modelnya
     * dimuat per 200. BUKAN chunkById: ORDER BY id LIMIT membuat planner
     * menyusuri primary key dan menyaring seluruh tabel, karena ia tidak tahu
     * bahwa unit terbuka yang sudah lewat tanggalnya selalu himpunan kecil.
     *
     * @param  callable(Builder<BloodBatch>): Builder<BloodBatch>  $window
     * @param  callable(Collection<int, BloodBatch>): void  $each
     */
    private function eachCandidate(callable $window, callable $each): void
    {
        $ids = $window($this->candidates())->pluck('id')->all();

        foreach (array_chunk($ids, 200) as $chunk) {
            $each(BloodBatch::query()->whereKey($chunk)->orderBy('id')->get());
        }
    }

    /**
     * Unit yang masih bisa menuju EXPIRED, diturunkan dari graf BatchStatus --
     * bukan daftar status tulisan tangan. Menyaring di query (index status +
     * expires_at) supaya tiap malam tidak memindai seluruh riwayat unit terminal.
     *
     * @return Builder<BloodBatch>
     */
    private function candidates(): Builder
    {
        $open = array_values(array_filter(
            BatchStatus::cases(),
            fn (BatchStatus $status): bool => $status->canTransitionTo(BatchStatus::EXPIRED),
        ));

        return BloodBatch::query()
            ->whereIn('status', array_map(fn (BatchStatus $status): string => $status->value, $open))
            ->whereNotNull('expires_at');
    }
}
