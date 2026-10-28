<?php

declare(strict_types=1);

namespace App\Modules\Notification\Domain;

/** Nilai harus sama dengan enum kolom notification_deliveries.status. */
enum DeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
    // Tidak dikirim karena penerima menolak kategorinya (kartu 488). Barisnya tetap
    // ditulis: "kenapa donor ini tidak dapat kabar" harus punya jawaban.
    case SUPPRESSED = 'suppressed';
}
