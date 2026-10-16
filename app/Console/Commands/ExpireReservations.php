<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BloodRequestAllocation;
use App\Modules\Request\Application\ReserveAllocatedUnits;
use Illuminate\Console\Command;

/**
 * Dry run adalah default: melepas reservasi mengubah stok yang terlihat petugas.
 * Scheduler memanggilnya dengan --force tiap jam.
 */
final class ExpireReservations extends Command
{
    protected $signature = 'request:expire-reservations {--force : benar-benar lepas reservasinya}';

    protected $description = 'Lepas reservasi yang sudah melewati batas waktunya dan kembalikan unitnya ke stok';

    public function __construct(private readonly ReserveAllocatedUnits $reservations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            $this->warn('DRY RUN -- tidak ada yang dilepas. Jalankan dengan --force untuk melepas reservasinya.');
        }

        // SENGAJA `<`, bukan `<=`: reservasi yang habis tepat detik ini belum lewat.
        // orderBy('blood_batch_id'): urutan kunci unit yang sama dengan pemesanan.
        // Id diambil dulu, bukan cursor(): pelepasan menulis di koneksi yang sama.
        $ids = BloodRequestAllocation::query()
            ->whereNotNull('reserved_at')
            ->whereNull('released_at')
            ->where('reservation_expires_at', '<', now())
            ->orderBy('blood_batch_id')
            ->pluck('id')
            ->all();

        $released = 0;

        foreach ($ids as $id) {
            $allocation = BloodRequestAllocation::query()->findOrFail($id);

            if ($force) {
                $this->reservations->release($allocation, 'reservation timeout');
            } else {
                $this->line("  akan dilepas: {$allocation->public_id} (habis {$allocation->reservation_expires_at?->toAtomString()})");
            }

            $released++;
        }

        $this->info($force
            ? "Melepas {$released} reservasi yang kedaluwarsa."
            : "Dry run: {$released} reservasi akan dilepas.");

        return self::SUCCESS;
    }
}
