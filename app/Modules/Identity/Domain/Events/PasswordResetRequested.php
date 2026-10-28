<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Events;

/**
 * Token reset password baru saja diterbitkan untuk akun ini (kartu 485). Modul
 * Notification yang mengirimkannya lewat jalur kirim kartu 460.
 *
 * Membawa tautan reset (rahasia) karena listener-nya SINKRON di proses yang sama:
 * event ini tidak pernah diserialisasi ke antrean. Jangan jadikan listener-nya
 * ShouldQueue -- tautannya akan mendarat di Redis dan failed_jobs.
 */
final class PasswordResetRequested
{
    public function __construct(
        public readonly int $userId,
        public readonly string $resetUrl,
        public readonly int $ttlMinutes,
        // Undangan akun staf baru (kartu 487), bukan lupa password: email lain, umur lain.
        public readonly bool $invitation = false,
    ) {}
}
