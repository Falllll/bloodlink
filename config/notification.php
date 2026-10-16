<?php

declare(strict_types=1);

$maxRecipients = env('NOTIFICATION_MAX_RECIPIENTS');

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
];
