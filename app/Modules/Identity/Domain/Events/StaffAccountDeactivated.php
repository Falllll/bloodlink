<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

/**
 * Akun staf dinonaktifkan (kartu 487). Token Sanctum sudah dicabut modul Identity;
 * modul Notification mencabut device token push-nya -- petugas yang keluar kerja
 * tidak boleh terus menerima notifikasi fasilitas di ponsel pribadinya.
 */
final class StaffAccountDeactivated
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
