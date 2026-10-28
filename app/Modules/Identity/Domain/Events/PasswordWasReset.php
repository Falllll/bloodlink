<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

/**
 * Password akun ini baru saja direset lewat token (kartu 485). Token Sanctum sudah
 * dicabut modul Identity; modul Notification mencabut device token push-nya --
 * password yang direset karena dicuri tidak boleh meninggalkan perangkat penyerang
 * tetap menerima notifikasi.
 */
final class PasswordWasReset
{
    public function __construct(
        public readonly int $userId,
    ) {}
}
