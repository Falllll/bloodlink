<?php

declare(strict_types=1);

namespace App\Shared\Matching;

/**
 * Port baca modul Donor untuk pencocokan donor (kartu 410). Modul Request tidak
 * boleh mengimpor DonorLocationQuery maupun DonorEligibilityService (deptrac:
 * antar-modul hanya lewat event). Kontrak ini tinggal di Shared, implementasinya
 * di modul Donor, dan pengikatnya di AppServiceProvider -- jadi ruleset deptrac
 * tidak bertambah, dan aturan kelayakan tetap hanya hidup di modul Donor.
 *
 * Semua masukan dan keluaran primitif: tidak ada model atau value object Donor
 * yang menyeberang, dan tidak ada data kontak.
 */
interface DonorDirectory
{
    /**
     * Donor terdekat dalam radius, terurut jarak menaik, tanpa penilaian kelayakan.
     *
     * @param  list<array{group: string, rh: string}>  $acceptablePairs  hasil gerbang kompatibilitas
     * @param  list<int>  $excludeDonorIds
     * @return list<NearbyDonor>
     */
    public function nearest(
        float $latitude,
        float $longitude,
        int $radiusMeters,
        array $acceptablePairs,
        array $excludeDonorIds,
        int $limit,
    ): array;

    /**
     * Kode alasan tidak layak per donor -- null berarti layak. Dinilai SEKALI untuk
     * semua donor (tanpa N+1).
     *
     * @param  list<int>  $donorIds
     * @return array<int, string|null>
     */
    public function ineligibilityReasons(array $donorIds): array;

    /**
     * Apakah donor punya kanal yang bisa dihubungi: device token aktif ATAU email --
     * dan tidak menolak panggilan darurat (kartu 488).
     *
     * @param  list<int>  $donorIds
     * @return array<int, bool>
     */
    public function reachability(array $donorIds): array;
}
