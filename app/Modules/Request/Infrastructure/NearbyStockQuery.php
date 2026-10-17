<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure;

use App\Modules\Request\Application\AllocateUnitsFefo;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pindaian PostGIS stok terdekat (kartu 380), meniru DonorLocationQuery:
 * ST_DWithin di WHERE (memakai facilities_location_gist), ST_Distance hanya di
 * SELECT/ORDER BY -- di WHERE ia memaksa seq scan.
 *
 * Mengembalikan AGREGAT per fasilitas, tidak pernah baris unit. Lewat DB::table,
 * bukan model: ScopedToFacility tetap utuh untuk semua jalur yang membaca unit.
 *
 * Golongan yang sah TIDAK dihitung di sini: pemanggil mengirim pasangan
 * (golongan, Rh) hasil CheckBloodCompatibility. Matriks kompatibilitas tidak
 * boleh hidup di dua tempat.
 */
final class NearbyStockQuery
{
    /**
     * @param  float  $latitude  Latitude in degrees, -90..90
     * @param  float  $longitude  Longitude in degrees, -180..180
     * @param  list<array{group: string, rh: string}>  $acceptablePairs  Pasangan donor yang lolos gerbang kompatibilitas
     * @param  DateTimeInterface  $usableUntil  Unit harus masih hidup sampai titik ini
     */
    public function aggregate(
        float $latitude,
        float $longitude,
        int $radiusMeters,
        string $component,
        array $acceptablePairs,
        DateTimeInterface $usableUntil,
    ): Builder {
        return DB::table('blood_batches')
            ->join('facilities', 'facilities.id', '=', 'blood_batches.facility_id')
            ->whereNotNull('facilities.location')
            ->whereNull('facilities.deleted_at')
            ->where('facilities.is_active', true)
            ->whereRaw(
                'ST_DWithin(facilities.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                [$longitude, $latitude, $radiusMeters]
            )
            // Hanya yang benar-benar bisa diambil: bukan reserved/quarantined/testing.
            ->where('blood_batches.status', AllocateUnitsFefo::RELEASED)
            ->where('blood_batches.expires_at', '>', $usableUntil)
            ->where('blood_batches.component', $component)
            ->where(function (Builder $q) use ($acceptablePairs): void {
                // Daftar kosong berarti tidak ada yang sah: WHERE FALSE, bukan "semua".
                $q->whereRaw('FALSE');

                foreach ($acceptablePairs as $pair) {
                    $q->orWhere(fn (Builder $p) => $p
                        ->where('blood_batches.blood_group', $pair['group'])
                        ->where('blood_batches.rh_factor', $pair['rh']));
                }
            })
            // Unit di perjalanan tetap released milik fasilitas asal (kartu 300/320).
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')
                ->from('stock_transfers')
                ->whereColumn('stock_transfers.blood_batch_id', 'blood_batches.id')
                ->whereIn('stock_transfers.status', AllocateUnitsFefo::OPEN_TRANSFER_STATUSES))
            // GROUP BY primary key: kolom facilities lain bergantung fungsional padanya.
            // geography tidak punya operator kesamaan, jadi location tidak bisa di-GROUP BY.
            ->groupBy('facilities.id')
            ->select('facilities.id', 'facilities.public_id', 'facilities.name')
            ->selectRaw('COUNT(*) AS compatible_units')
            ->selectRaw(
                'ST_Distance(facilities.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance_meters',
                [$longitude, $latitude]
            )
            ->orderBy('distance_meters')
            ->orderBy('facilities.id');
    }
}
