<?php

declare(strict_types=1);

$radius = env('MATCHING_NEARBY_RADIUS_METERS');
$geoEnabled = env('MATCHING_REDIS_GEO_ENABLED');
$geoKey = env('MATCHING_REDIS_GEO_KEY');

return [
    /*
     | Radius pindaian stok terdekat, dalam meter. Angka OPERASIONAL:
     | ditetapkan dari jarak tempuh yang masuk akal untuk kurir, bukan dari
     | pedoman medis mana pun. Boleh diubah tanpa rujukan.
     | Baris kosong di .env dibaca sebagai string kosong, bukan null.
     */
    'nearby_stock_radius_meters' => $radius === null || $radius === '' ? 30000 : (int) $radius,

    /*
     | Tangga radius kampanye panggilan donor, dalam meter. Angka OPERASIONAL,
     | bukan medis. Kartu 400 menaiki tangga ini; kartu 410 cuma menerima salah
     | satunya (endpoint pratinjau menolak radius di luar daftar ini).
     */
    'staged_radii_meters' => [5000, 15000, 30000],

    /*
     | Kill-switch indeks GEO Redis untuk lokasi donor (kartu 390). Default MATI:
     | selama mati, pencarian donor memakai PostGIS saja, jadi cache yang rusak
     | tidak pernah bisa menghentikan permintaan darurat. PostGIS tetap otoritatif.
     | Baris kosong dibaca sebagai mati; env() sudah mengubah "true"/"false" jadi bool.
     */
    'redis_geo_enabled' => $geoEnabled === null || $geoEnabled === '' ? false : (bool) $geoEnabled,

    /*
     | Nama key sorted set GEO. Laravel menambahkan prefix koneksi Redis
     | (database.redis.options.prefix) di depannya.
     */
    'redis_geo_key' => $geoKey === null || $geoKey === '' ? 'geo:donors' : (string) $geoKey,
];
