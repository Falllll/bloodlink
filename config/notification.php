<?php

declare(strict_types=1);

$maxRecipients = env('NOTIFICATION_MAX_RECIPIENTS');
$maxAttempts = env('NOTIFICATION_DELIVERY_MAX_ATTEMPTS');
$baseBackoff = env('NOTIFICATION_DELIVERY_BASE_BACKOFF_SECONDS');
$jitterRatio = env('NOTIFICATION_DELIVERY_JITTER_RATIO');

return [
    /*
     | Kanal yang boleh dipakai sistem ini. SMS dan WhatsApp Business API
     | sengaja TIDAK ada di sini: keduanya dibayar per pesan, sementara
     | kampanye panggilan donor (kartu 400) mengirim ke puluhan sampai
     | ratusan penerima sekaligus. Email (SMTP gratis) dan FCM push tidak
     | punya biaya per pesan.
     |
     | Menambah kanal di sini TIDAK cukup -- kanalnya juga harus punya case
     | di NotificationChannel dan implementasi pengirimnya. Nilai yang tidak
     | punya case enum membuat ChannelPolicy gagal dibangun.
     |
     | Dibaca HANYA oleh AppServiceProvider lewat config('notification.channels'),
     | yang menyerahkannya ke ChannelPolicy. Kelas lain bertanya ke ChannelPolicy.
     */
    'channels' => [
        'email',
        'push',
    ],

    /*
     | Batas kipas-keluar satu kampanye. Angka OPERASIONAL, bukan medis:
     | ditetapkan supaya satu permintaan tidak pernah membangunkan seluruh
     | basis data donor sekaligus. Boleh diubah tanpa rujukan apa pun.
     | Baris kosong di .env dibaca sebagai string kosong, bukan null.
     */
    'max_recipients_per_campaign' => $maxRecipients === null || $maxRecipients === ''
        ? 200
        : (int) $maxRecipients,

    /*
     | Jumlah percobaan kirim per delivery, TERMASUK yang pertama (kartu 470).
     | Angka OPERASIONAL, bukan medis. Hanya kegagalan TRANSIENT yang diulang;
     | PERMANENT langsung dead-letter di percobaan pertama.
     | Baris kosong di .env dibaca sebagai string kosong, bukan null -- dan
     | (int) '' = 0, sedangkan $tries = 0 berarti job diulang TANPA BATAS.
     */
    'delivery_max_attempts' => $maxAttempts === null || $maxAttempts === ''
        ? 5
        : (int) $maxAttempts,

    /*
     | Jeda dasar backoff eksponensial: base * 2^i sebelum percobaan ke-(i+2).
     | Angka OPERASIONAL, bukan medis. Baris kosong dibaca sebagai null.
     |
     | HUBUNGAN DENGAN retry_after (config/queue.php, koneksi redis): total
     | jeda maksimum = base * (2^(attempts-1) - 1) * (1 + jitter)
     |               = 10 * 15 * 1.3 = 195 detik dengan nilai bawaan,
     | dan retry_after diset 210. Naikkan salah satu angka di sini = periksa
     | retry_after di sana. DeliveryResilienceTest membandingkan keduanya.
     */
    'delivery_base_backoff_seconds' => $baseBackoff === null || $baseBackoff === ''
        ? 10
        : (int) $baseBackoff,

    /*
     | Geseran acak tiap jeda, +/- rasio ini (0.3 = +/-30%). Angka OPERASIONAL,
     | bukan medis. Tanpa jitter, semua job yang gagal pada pemadaman yang sama
     | kembali di detik yang identik dan memukul layanan yang baru pulih serentak.
     | Jaga di bawah 1/3: di atasnya jeda ke-(i+1) bisa lebih pendek dari jeda ke-i.
     | Baris kosong dibaca sebagai null.
     */
    'delivery_jitter_ratio' => $jitterRatio === null || $jitterRatio === ''
        ? 0.3
        : (float) $jitterRatio,
];
