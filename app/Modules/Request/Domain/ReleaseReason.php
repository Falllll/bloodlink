<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Kenapa sebuah alokasi (dan reservasinya) dilepas oleh jalur kompensasi.
 * Kolom release_reason dan reservation_release_reason tetap string(255): enum
 * ini hanya menyeragamkan nilai yang ditulis dari PHP supaya laporan bisa
 * mengelompokkan. Pelepasan manual kartu 320 tetap menyimpan teks bebas petugas.
 *
 * RESERVATION_TIMEOUT: ExpireReservations (kartu 330) masih menulis teks bebas
 * 'reservation timeout'. Menyeragamkannya di luar lingkup kartu 360 -- dicatat,
 * belum diubah.
 */
enum ReleaseReason: string
{
    case REQUEST_CANCELLED = 'request_cancelled';
    case REQUEST_EXPIRED = 'request_expired';
    case CROSSMATCH_INCOMPATIBLE = 'crossmatch_incompatible';
    case RESERVATION_TIMEOUT = 'reservation_timeout';
    case MANUAL = 'manual';
}
