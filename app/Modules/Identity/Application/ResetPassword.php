<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Application\Exceptions\PasswordResetRejected;
use App\Modules\Identity\Domain\Events\PasswordWasReset;
use Illuminate\Support\Facades\DB;

/**
 * Menukar token reset dengan password baru (kartu 485). Satu transaksi: password
 * diganti, token ditandai terpakai, SEMUA token Sanctum akun dicabut, dan device
 * token push dicabut lewat PasswordWasReset -- password yang direset karena dicuri
 * tidak boleh meninggalkan sesi penyerang tetap hidup.
 */
final class ResetPassword
{
    public function handle(string $token, string $newPassword): void
    {
        DB::transaction(function () use ($token, $newPassword): void {
            // lockForUpdate: dua permintaan dengan token yang sama berjalan berurutan,
            // dan yang kedua melihat used_at terisi.
            $row = DB::table('user_password_resets')
                ->where('token_hash', IssuePasswordReset::hash($token))
                ->lockForUpdate()
                ->first();

            if ($row === null || $row->used_at !== null || now()->greaterThanOrEqualTo($row->expires_at)) {
                throw PasswordResetRejected::invalidToken();
            }

            $user = User::query()->whereKey($row->user_id)->where('is_active', true)->first();

            if ($user === null) {
                throw PasswordResetRejected::invalidToken();
            }

            // Cast 'hashed' di User yang meng-hash; audit me-redact kolom password.
            $user->forceFill(['password' => $newPassword])->save();

            DB::table('user_password_resets')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            $user->tokens()->delete();

            event(new PasswordWasReset($user->id));
        });
    }
}
