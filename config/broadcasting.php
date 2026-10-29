<?php

declare(strict_types=1);

/*
 * Siaran realtime (kartu 490): Reverb self-hosted, service Compose `reverb`.
 * Koneksi pusher/ably bawaan installer SENGAJA dibuang -- keduanya layanan
 * berbayar, dan AGENTS.md mewajibkan semua tool gratis.
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                // REVERB_HOST/PORT = tempat Laravel MENGIRIM siaran (nama service
                // Compose). Bukan REVERB_SERVER_HOST/PORT, yang menentukan tempat
                // server MENDENGAR. Tertukar = siaran hilang tanpa satu pun error.
                'host' => env('REVERB_HOST', 'reverb'),
                'port' => (int) env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
        ],

        'log' => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],
];
