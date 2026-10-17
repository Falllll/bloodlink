<?php

declare(strict_types=1);

namespace App\Modules\Request\Application;

use App\Models\BloodRequest;
use App\Models\Facility;
use App\Modules\Request\Domain\NearbyStockEntry;
use App\Modules\Request\Domain\NearbyStockScan;
use App\Modules\Request\Infrastructure\NearbyStockQuery;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Illuminate\Support\Facades\DB;

/**
 * Cari stok yang sudah ada lebih dulu, baru panggil donor (kartu 380). Pindaian
 * adalah BANTUAN, bukan syarat: apa pun yang membuatnya tidak bisa jalan tidak
 * pernah menggagalkan pembuatan permintaan darurat.
 */
final class ScanNearbyStock
{
    public function __construct(
        private readonly CheckBloodCompatibility $compatibility,
        private readonly NearbyStockQuery $query,
    ) {}

    public function handle(BloodRequest $request, Facility $requester): NearbyStockScan
    {
        $direction = CheckBloodCompatibility::directionFor($request->component);

        if ($direction === null) {
            return new NearbyStockScan(NearbyStockScan::NO_SOURCED_RULE, []);
        }

        $origin = DB::table('facilities')
            ->where('id', $requester->id)
            ->whereNotNull('location')
            ->selectRaw('ST_Y(location::geometry) AS latitude, ST_X(location::geometry) AS longitude')
            ->first();

        if ($origin === null) {
            return new NearbyStockScan(NearbyStockScan::NO_REQUESTER_LOCATION, []);
        }

        // Pasangan donor yang sah diturunkan dari GERBANG, bukan ditulis di sini:
        // setiap kombinasi ditanyakan ke CheckBloodCompatibility (aturan terseed
        // ber-source_reference). RhD plasma tidak diperiksa gerbang, jadi kedua Rh lolos.
        $pairs = [];
        foreach (BloodGroup::cases() as $group) {
            foreach (RhFactor::cases() as $rh) {
                if ($this->compatibility->check($direction, $request->blood_group, $request->rh_factor, $group, $rh)->isCompatible()) {
                    $pairs[] = ['group' => $group->value, 'rh' => $rh->value];
                }
            }
        }

        // Kantong yang mati sebelum jadwal transfusi tidak memenuhi permintaan ini
        // (turunan yang sama dengan FEFO kartu 320).
        $usableUntil = max(now(), $request->needed_by);

        $rows = $this->query->aggregate(
            (float) $origin->latitude,
            (float) $origin->longitude,
            (int) config('matching.nearby_stock_radius_meters'),
            $request->component->value,
            $pairs,
            $usableUntil,
        )->get();

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = new NearbyStockEntry(
                (string) $row->public_id,
                (string) $row->name,
                (int) round((float) $row->distance_meters),
                (int) $row->compatible_units,
                (int) $row->id === $requester->id,
            );
        }

        // Peminta selalu pertama: jaraknya 0, tapi pembulatan floating ST_Distance
        // tidak boleh jadi satu-satunya penjamin urutan itu.
        usort($entries, fn (NearbyStockEntry $a, NearbyStockEntry $b): int => [! $a->isSelf, $a->distanceMeters] <=> [! $b->isSelf, $b->distanceMeters]);

        return new NearbyStockScan(NearbyStockScan::SCANNED, $entries);
    }
}
