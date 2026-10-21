<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure;

use App\Shared\Auth\FacilityMember;
use App\Shared\Reporting\Ratio;
use App\Shared\Reporting\ReportScope;
use App\Shared\Reporting\ReportWindow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rasio crossmatch-ke-transfusi (C:T) per bucket (kartu 440).
 *
 * Pembilang = SEMUA crossmatch yang dikerjakan (performed_at), apa pun result-nya:
 * pemeriksaan yang hasilnya incompatible atau inconclusive tetap dikerjakan dan
 * tetap memakan unit serta waktu lab. Jangan menyaring result.
 * Penyebut = transfusi yang dimulai (started_at) di bucket yang sama.
 *
 * crossmatches dan transfusions tidak punya facility_id; scope fasilitas di-join
 * lewat blood_request_allocations -> blood_requests.facility_id, yaitu fasilitas
 * PEMINTA, bukan pemilik kantong. Keduanya berbeda saat kantong datang dari transfer.
 */
final class CrossmatchTransfusionReportQuery
{
    /** @return list<array<string, mixed>> */
    public function ratio(ReportWindow $window, ?FacilityMember $user): array
    {
        $crossmatches = $window->fill(
            $this->countPerBucket('crossmatches', 'performed_at', $window, $user)->get(),
            ['n'],
        );
        $transfusions = $window->fill(
            $this->countPerBucket('transfusions', 'started_at', $window, $user)->get(),
            ['n'],
        );

        return array_map(fn (array $c, array $t): array => [
            'period_start' => $c['period_start'],
            ...Ratio::of($c['n'], $t['n']),
        ], $crossmatches, $transfusions);
    }

    /**
     * Publik supaya EXPLAIN di test memeriksa query yang sama persis (kartu 445).
     *
     * @param  'crossmatches'|'transfusions'  $table
     */
    public function countPerBucket(string $table, string $timeColumn, ReportWindow $window, ?FacilityMember $user): Builder
    {
        $unit = $window->granularity->sqlUnit();

        $query = DB::table($table)
            ->join('blood_request_allocations', 'blood_request_allocations.id', '=', "{$table}.blood_request_allocation_id")
            ->join('blood_requests', 'blood_requests.id', '=', 'blood_request_allocations.blood_request_id')
            // $unit dari literal enum ReportGranularity::sqlUnit(), lihat peringatan di sana.
            ->selectRaw("date_trunc('{$unit}', {$table}.{$timeColumn})::date AS period_start")
            ->selectRaw('COUNT(*) AS n')
            ->where("{$table}.{$timeColumn}", '>=', $window->start)
            ->where("{$table}.{$timeColumn}", '<', $window->end)
            ->groupByRaw('1');

        return ReportScope::apply($query, 'blood_requests.facility_id', $user);
    }
}
