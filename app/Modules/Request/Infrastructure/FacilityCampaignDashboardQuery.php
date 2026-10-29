<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure;

use App\Modules\Request\Domain\CampaignResponse;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ringkasan kampanye AKTIF satu fasilitas untuk dashboard petugas (kartu 500).
 *
 * SATU query untuk seluruh fasilitas, bukan loop yang memanggil agregat per
 * permintaan (kartu 445): jumlah query tidak boleh tumbuh bersama jumlah permintaan
 * terbuka -- QueryBudgetTest menjaganya.
 *
 * LEFT JOIN dari kampanye ke kontak, bukan sebaliknya: kampanye aktif yang belum
 * menghubungi siapa pun (tahap pertama kosong) justru yang paling perlu dilihat
 * petugas, dan JOIN dari kontak akan membuangnya.
 *
 * Bentuk tiap entri SAMA dengan DonorCampaignController::summary(), supaya klien
 * cukup punya satu parser. Tanpa identitas donor.
 */
final class FacilityCampaignDashboardQuery
{
    /** @return list<array<string, mixed>> */
    public function handle(int $facilityId): array
    {
        $rows = DB::table('donor_campaigns as c')
            ->join('blood_requests as r', 'r.id', '=', 'c.blood_request_id')
            ->leftJoin('donor_campaign_contacts as cc', 'cc.donor_campaign_id', '=', 'c.id')
            ->where('r.facility_id', $facilityId)
            ->whereNull('c.ended_at')
            ->groupBy('c.id', 'c.public_id', 'r.public_id', 'c.current_radius_meters', 'c.started_at', 'c.stage_entered_at', 'cc.radius_meters')
            ->orderByDesc('c.started_at')
            ->orderByDesc('c.id')
            ->orderBy('cc.radius_meters')
            ->select('c.id', 'c.public_id', 'r.public_id as blood_request_id', 'c.current_radius_meters', 'c.started_at', 'c.stage_entered_at', 'cc.radius_meters')
            // COUNT(cc.id), bukan COUNT(*): baris LEFT JOIN tanpa kontak dihitung 0.
            ->selectRaw('COUNT(cc.id) AS contacted')
            ->selectRaw('COUNT(cc.id) FILTER (WHERE cc.response = ?) AS accepted', [CampaignResponse::ACCEPTED->value])
            ->selectRaw('COUNT(cc.id) FILTER (WHERE cc.response = ?) AS declined', [CampaignResponse::DECLINED->value])
            ->get();

        /** @var array<int, array<string, mixed>> $campaigns */
        $campaigns = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $campaigns[$id] ??= [
                'id' => (string) $row->public_id,
                'blood_request_id' => (string) $row->blood_request_id,
                'current_radius_meters' => (int) $row->current_radius_meters,
                'started_at' => Carbon::parse((string) $row->started_at)->format(DateTimeInterface::ATOM),
                'stage_entered_at' => Carbon::parse((string) $row->stage_entered_at)->format(DateTimeInterface::ATOM),
                // Selalu null di sini (hanya kampanye aktif) -- tetap ada demi bentuk yang sama.
                'ended_at' => null,
                'end_reason' => null,
                'stages' => [],
            ];

            // Baris tanpa kontak: kampanye tanpa tahap, bukan tahap berisi nol.
            if ($row->radius_meters !== null) {
                $campaigns[$id]['stages'][] = [
                    'radius_meters' => (int) $row->radius_meters,
                    'contacted' => (int) $row->contacted,
                    'accepted' => (int) $row->accepted,
                    'declined' => (int) $row->declined,
                ];
            }
        }

        return array_values(array_map(function (array $campaign): array {
            /** @var list<array<string, int>> $stages */
            $stages = $campaign['stages'];
            $campaign['totals'] = [
                'contacted' => array_sum(array_column($stages, 'contacted')),
                'accepted' => array_sum(array_column($stages, 'accepted')),
                'declined' => array_sum(array_column($stages, 'declined')),
            ];

            return $campaign;
        }, $campaigns));
    }
}
