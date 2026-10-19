<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Donor\Infrastructure\DonorGeoIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membangun ulang indeks GEO Redis dari PostGIS (kartu 390). Jaring pengaman
 * untuk event yang hilang -- PostGIS otoritatif, Redis hanya cache.
 * Dry run adalah default. Scheduler memanggilnya dengan --force tiap malam.
 *
 * Tanpa visibleTo(): command berjalan tanpa user, dan scope yang gagal-tertutup
 * akan membalas nol baris tanpa error.
 */
final class ReconcileDonorGeoIndex extends Command
{
    protected $signature = 'donor:reconcile-geo {--force : benar-benar tulis ke Redis}';

    protected $description = 'Bangun ulang indeks GEO Redis lokasi donor dari kolom PostGIS donors.location';

    public function __construct(private readonly DonorGeoIndex $index)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $this->index->enabled()) {
            $this->warn('matching.redis_geo_enabled mati -- indeks GEO tidak dipakai, tidak ada yang ditulis.');

            return self::SUCCESS;
        }

        if (! $force) {
            $this->warn('DRY RUN -- tidak ada yang ditulis. Jalankan dengan --force untuk menulis ke Redis.');
        }

        $written = 0;
        /** @var array<int, true> $seen */
        $seen = [];

        // ST_Y = latitude, ST_X = longitude -- terbalik dari dugaan pertama.
        DB::table('donors')
            ->whereNotNull('location')
            ->whereNull('deleted_at')
            ->select('id')
            ->selectRaw('ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude')
            ->chunkById(500, function ($rows) use ($force, &$written, &$seen): void {
                foreach ($rows as $row) {
                    if ($force) {
                        $this->index->put((int) $row->id, (float) $row->latitude, (float) $row->longitude);
                    }

                    $seen[(int) $row->id] = true;
                    $written++;
                }
            });

        // Membangun ulang berarti juga MEMBUANG: donor yang lokasinya dihapus atau
        // donornya di-soft-delete tidak boleh tetap muncul di radius lamanya.
        $stale = array_values(array_filter(
            $this->index->members(),
            fn (int $donorId): bool => ! isset($seen[$donorId]),
        ));

        if ($force) {
            foreach ($stale as $donorId) {
                $this->index->forget($donorId);
            }
        }

        $removed = count($stale);

        $this->info($force
            ? "Menulis {$written} lokasi donor, membuang {$removed} yang basi. Isi indeks sekarang: {$this->index->count()}."
            : "Dry run: {$written} lokasi donor akan ditulis, {$removed} yang basi akan dibuang. Isi indeks sekarang: {$this->index->count()}.");

        return self::SUCCESS;
    }
}
