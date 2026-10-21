<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DonorCallClaim;
use App\Modules\Request\Domain\RequestStatus;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Membersihkan klaim panggilan donor (kartu 480) milik permintaan yang sudah
 * selesai. Dry run adalah default: klaim yang dihapus terlalu cepat membuat donor
 * yang sama bisa dipanggil lagi untuk permintaan yang -- kalau statusnya keliru --
 * ternyata masih berjalan. Scheduler memanggilnya dengan --force tiap hari.
 */
final class PruneDonorCallClaims extends Command
{
    /**
     * Masa retensi sesudah klaim dibuat. Angka OPERASIONAL, bukan medis: cukup
     * panjang untuk menjawab "siapa yang dipanggil untuk permintaan ini" saat
     * permintaan ditinjau ulang.
     */
    private const int RETENTION_DAYS = 90;

    protected $signature = 'notification:prune-claims {--force : benar-benar hapus}';

    protected $description = 'Hapus klaim panggilan donor milik permintaan yang sudah selesai dan lewat masa retensi';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if (! $force) {
            $this->warn('DRY RUN -- tidak ada yang dihapus. Jalankan dengan --force untuk menghapus.');
        }

        // Hanya permintaan TERMINAL: klaim permintaan yang masih terbuka adalah
        // penjaga yang sedang bekerja, berapa pun umurnya.
        $query = DonorCallClaim::query()
            ->where('claimed_at', '<', now()->subDays(self::RETENTION_DAYS))
            ->whereIn('blood_request_id', function ($sub): void {
                $sub->select('id')->from('blood_requests')->whereIn('status', [
                    RequestStatus::FULFILLED->value,
                    RequestStatus::CANCELLED->value,
                    RequestStatus::EXPIRED->value,
                ]);
            });

        $count = $force ? $this->delete($query) : $query->count();

        $this->info($force
            ? "Deleted {$count} donor call claim(s)."
            : "{$count} donor call claim(s) would be deleted.");

        return self::SUCCESS;
    }

    /** @param  Builder<DonorCallClaim>  $query */
    private function delete(Builder $query): int
    {
        return (int) $query->delete();
    }
}
