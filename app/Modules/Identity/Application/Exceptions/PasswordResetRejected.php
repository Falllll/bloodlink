<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

/**
 * Token reset tidak bisa dipakai (kartu 485). SATU pesan dan SATU kode untuk semua
 * sebab -- tidak dikenal, kedaluwarsa, sudah dipakai, akun nonaktif -- supaya
 * endpoint ini tidak menjadi oracle tentang token atau akun mana yang ada.
 */
final class PasswordResetRejected extends DomainException
{
    public static function invalidToken(): self
    {
        return new self('This password reset link is invalid or has expired. Request a new one.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::PASSWORD_RESET_TOKEN_INVALID;
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
