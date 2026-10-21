<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Shared\Auth\FacilityMember;
use App\Shared\Reporting\Ratio;
use App\Shared\Reporting\ReportScope;
use App\Shared\Reporting\ReportWindow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agregat laporan inventori (kartu 440), dihitung dari blood_batch_events, BUKAN
 * dari blood_batches.status. Status sekarang tidak punya dimensi waktu: ia hanya
 * menjawab "berapa kantong yang SEKARANG discarded", bukan "berapa yang terbuang
 * bulan lalu". Dashboard yang menampilkan angka itu sebagai tren bulanan berbohong
 * tanpa satu pun error.
 *
 * Bentuk WHERE (facility_id, to_status, occurred_at) sengaja mengikuti index
 * blood_batch_events_facility_id_to_status_occurred_at_index dan
 * blood_batch_events_to_status_occurred_at_index (operator global).
 */
final class InventoryReportQuery
{
    /**
     * Wastage rate periodik: pembilang = transisi ke discarded/expired, penyebut =
     * transisi testing -> released (rilis gerbang lab) di bucket yang sama.
     *
     * SATU KANTONG BISA MENYUMBANG KE DUA BUCKET, DAN ITU DISENGAJA. to_status adalah
     * status TUJUAN transisi, jadi kantong yang di-release bulan Januari lalu expired
     * bulan Februari dihitung di penyebut Januari dan di pembilang Februari. Itulah
     * wastage rate periodik: berapa yang masuk stok vs berapa yang terbuang dalam
     * periode itu, bukan nasib per kantong. Ini bukan bug double counting -- jangan
     * "diperbaiki" dengan DISTINCT blood_batch_id atau dengan mengelompokkan per unit.
     *
     * Penyebut hanya from_status = testing: reserved -> released (reservasi dilepas,
     * ReturnAllocatedUnit) juga ber-to_status released, dan menghitungnya akan
     * menggelembungkan penyebut tiap kali sebuah reservasi dibatalkan.
     *
     * @return list<array<string, mixed>>
     */
    public function wastage(ReportWindow $window, ?FacilityMember $user): array
    {
        $rows = $this->wastageQuery($window, $user)->get();

        return array_map(function (array $bucket): array {
            $wasted = $bucket['discarded'] + $bucket['expired'];

            return [
                'period_start' => $bucket['period_start'],
                'discarded' => $bucket['discarded'],
                'expired' => $bucket['expired'],
                'released' => $bucket['released'],
                ...Ratio::of($wasted, $bucket['released']),
            ];
        }, $window->fill($rows, ['discarded', 'expired', 'released']));
    }

    /** Publik supaya EXPLAIN di test memeriksa query yang sama persis (kartu 445). */
    public function wastageQuery(ReportWindow $window, ?FacilityMember $user): Builder
    {
        $unit = $window->granularity->sqlUnit();

        $query = DB::table('blood_batch_events')
            // $unit dari literal enum ReportGranularity::sqlUnit(), lihat peringatan di sana.
            ->selectRaw("date_trunc('{$unit}', occurred_at)::date AS period_start")
            ->selectRaw('COUNT(*) FILTER (WHERE to_status = ?) AS discarded', [BatchStatus::DISCARDED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE to_status = ?) AS expired', [BatchStatus::EXPIRED->value])
            ->selectRaw('COUNT(*) FILTER (WHERE to_status = ? AND from_status = ?) AS released', [BatchStatus::RELEASED->value, BatchStatus::TESTING->value])
            ->whereIn('to_status', [BatchStatus::DISCARDED->value, BatchStatus::EXPIRED->value, BatchStatus::RELEASED->value])
            ->where('occurred_at', '>=', $window->start)
            ->where('occurred_at', '<', $window->end)
            ->groupByRaw('1');

        return ReportScope::apply($query, 'facility_id', $user);
    }

    /**
     * Arus stok per bucket: jumlah transisi KE tiap status.
     *
     * Sengaja arus, bukan LEVEL stok. Transfer antarfasilitas memindah facility_id
     * unit tanpa mengubah statusnya, jadi tanpa event (TransitionStockTransfer);
     * level stok per fasilitas yang direkonstruksi dari event akan salah begitu ada
     * satu transfer. Level stok saat ini ada di GET /blood-batches.
     *
     * @return list<array<string, mixed>>
     */
    public function stockFlow(ReportWindow $window, ?FacilityMember $user): array
    {
        $statuses = self::statuses();
        $rows = $this->stockFlowQuery($window, $user)->get();

        return array_map(fn (array $bucket): array => [
            'period_start' => $bucket['period_start'],
            'transitions_to' => array_intersect_key($bucket, array_flip($statuses)),
        ], $window->fill($rows, $statuses));
    }

    /** Publik supaya EXPLAIN di test memeriksa query yang sama persis (kartu 445). */
    public function stockFlowQuery(ReportWindow $window, ?FacilityMember $user): Builder
    {
        $unit = $window->granularity->sqlUnit();
        $statuses = self::statuses();

        $query = DB::table('blood_batch_events')
            // $unit dari literal enum ReportGranularity::sqlUnit(), lihat peringatan di sana.
            ->selectRaw("date_trunc('{$unit}', occurred_at)::date AS period_start")
            // Secara logika tidak menyaring apa pun (to_status NOT NULL dan selalu salah
            // satu kasus ini), tapi tanpa predikat ini index (to_status, occurred_at) tidak
            // bisa dipakai dan jalur operator global memindai SELURUH riwayat event.
            // Kartu 445, 600k baris: Seq Scan cost 16970 -> Bitmap Index Scan cost 9080,
            // dan selisihnya tumbuh bersama riwayat. Jangan dihapus sebagai "redundan".
            ->whereIn('to_status', $statuses)
            ->where('occurred_at', '>=', $window->start)
            ->where('occurred_at', '<', $window->end)
            ->groupByRaw('1');

        foreach ($statuses as $status) {
            // Alias dari BatchStatus::cases(), bukan input; tetap dikutip sebagai identifier.
            $query->selectRaw("COUNT(*) FILTER (WHERE to_status = ?) AS \"{$status}\"", [$status]);
        }

        return ReportScope::apply($query, 'facility_id', $user);
    }

    /** @return list<string> */
    private static function statuses(): array
    {
        return array_map(fn (BatchStatus $s): string => $s->value, BatchStatus::cases());
    }
}
