<?php

declare(strict_types=1);

$radius = env('MATCHING_NEARBY_RADIUS_METERS');

return [
    /*
     | Radius pindaian stok terdekat, dalam meter. Angka OPERASIONAL:
     | ditetapkan dari jarak tempuh yang masuk akal untuk kurir, bukan dari
     | pedoman medis mana pun. Boleh diubah tanpa rujukan.
     | Baris kosong di .env dibaca sebagai string kosong, bukan null.
     */
    'nearby_stock_radius_meters' => $radius === null || $radius === '' ? 30000 : (int) $radius,
];
