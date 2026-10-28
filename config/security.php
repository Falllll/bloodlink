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

        // Lupa password (kartu 485), per JAM per ALAMAT EMAIL, di atas limiter `auth`
        // per IP. Tanpa bucket per email, satu alamat bisa dibanjiri email reset dari
        // banyak IP. Bucket ini tidak bergantung pada ada-tidaknya akun, jadi 429-nya
        // tidak membocorkan apa pun. Angka OPERASIONAL.
        'forgot_password_per_email_per_hour' => (int) env('FORGOT_PASSWORD_RATE_LIMIT_PER_EMAIL_PER_HOUR', 3),
    ],

    // Umur token reset password (kartu 485), menit. Pendek dan sekali pakai: tautan di
    // kotak masuk yang bocor tidak boleh berguna lama. Angka OPERASIONAL.
    'password_reset_ttl_minutes' => (int) env('PASSWORD_RESET_TTL_MINUTES', 60),

    // Batas bucket per permintaan laporan (kartu 440). Angka OPERASIONAL: satu
    // tahun harian. Tanpa batas, granularity=day&from=2000-01-01 adalah DoS.
    'report_max_buckets' => (int) env('REPORT_MAX_BUCKETS', 366),

    'hsts_max_age' => env('HSTS_MAX_AGE', 31536000),

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),
];
