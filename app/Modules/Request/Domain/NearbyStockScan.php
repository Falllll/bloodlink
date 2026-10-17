<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Hasil pindaian beserta alasannya kalau pindaian tidak dijalankan. Daftar kosong
 * saja tidak cukup: "tidak ada stok" dan "tidak bisa dipindai" menuntut tindakan
 * berbeda dari petugas yang sedang panik.
 */
final readonly class NearbyStockScan
{
    public const string SCANNED = 'scanned';

    /** Fasilitas peminta belum punya koordinat. */
    public const string NO_REQUESTER_LOCATION = 'no_requester_location';

    /** Komponen tanpa aturan kompatibilitas bersumber (trombosit, Rujukan §12.6). */
    public const string NO_SOURCED_RULE = 'no_sourced_rule';

    /** @param  list<NearbyStockEntry>  $entries */
    public function __construct(
        public string $status,
        public array $entries,
    ) {}
}
