<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Requests\ForgotPasswordRequest;
use App\Modules\Identity\Http\Requests\ResetPasswordRequest;
use App\Modules\Identity\Infrastructure\Jobs\SendPasswordResetLink;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Lupa, reset, dan ganti password (kartu 485). Hanya pemilik akun atas akunnya
 * sendiri; admin yang mereset password orang lain adalah kartu 487.
 */
final class PasswordController
{
    /**
     * 202 yang identik untuk email yang ada maupun tidak, dengan kerja yang identik:
     * validasi format + satu push antrean. Mencari akun, menulis token, dan mengirim
     * email terjadi di SendPasswordResetLink, jadi durasinya pun tidak membocorkan.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        SendPasswordResetLink::dispatch((string) $request->validated('email'));

        return ApiResponse::success([
            'message' => 'If an account exists for this email, a password reset link has been sent.',
        ])->setStatusCode(202);
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        $reset->handle((string) $request->validated('token'), (string) $request->validated('password'));

        // Tanpa token baru: semua sesi baru saja dicabut, dan pengguna masuk lagi lewat login.
        return ApiResponse::success(['message' => 'Password has been reset. Sign in with the new password.']);
    }

    /**
     * Ganti password oleh pengguna yang sedang masuk. Sesi LAIN dicabut (perangkat yang
     * mungkin masih memegang token lama); sesi yang dipakai sekarang tetap hidup.
     */
    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        $user->forceFill(['password' => (string) $request->validated('password')])->save();

        // API ini stateless (Bearer token Sanctum), jadi token saat ini selalu baris
        // personal_access_tokens -- semua baris LAIN milik akun ini yang dicabut.
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return ApiResponse::success(['message' => 'Password has been changed. Other sessions have been signed out.']);
    }
}
