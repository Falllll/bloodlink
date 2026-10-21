<?php

declare(strict_types=1);

return [
    'rate_limit' => [
        'per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),
        'auth_per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 10),

        // Permintaan darurat (kartu 420), per JAM. Angka OPERASIONAL, bukan medis:
        // tidak ada pedoman tentang berapa permintaan darurat per jam yang wajar.
        // Dipilih supaya satu petugas gugup atau bug retry klien tidak membakar daftar
        // donor kota. Jangan dinaikkan tiap kali terasa sempit.
        'urgent_per_user_per_hour' => (int) env('URGENT_RATE_LIMIT_PER_USER_PER_HOUR', 6),
        'urgent_per_facility_per_hour' => (int) env('URGENT_RATE_LIMIT_PER_FACILITY_PER_HOUR', 20),
    ],

    // Batas bucket per permintaan laporan (kartu 440). Angka OPERASIONAL: satu
    // tahun harian. Tanpa batas, granularity=day&from=2000-01-01 adalah DoS.
    'report_max_buckets' => (int) env('REPORT_MAX_BUCKETS', 366),

    'hsts_max_age' => env('HSTS_MAX_AGE', 31536000),

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
];
