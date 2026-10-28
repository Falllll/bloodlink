<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Events\PasswordResetRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menerbitkan satu token reset password (kartu 485). Yang disimpan hanya hash
 * SHA-256-nya; token aslinya hanya pernah ada di tautan email. Token lama yang
 * belum terpakai digugurkan, jadi hanya tautan TERBARU yang berlaku.
 */
final class IssuePasswordReset
{
    /**
     * @param  bool  $invitation  undangan akun staf baru (kartu 487): token yang sama
     *                            dan endpoint penukaran yang sama, tapi umurnya lebih
     *                            panjang dan emailnya berbeda.
     * @return string token polos, untuk dimasukkan ke tautan
     */
    public function handle(User $user, bool $invitation = false): string
    {
        $token = Str::random(64);
        $ttl = $invitation
            ? (int) config('security.staff_invitation_ttl_hours') * 60
            : (int) config('security.password_reset_ttl_minutes');

        DB::transaction(function () use ($user, $token, $ttl, $invitation): void {
            DB::table('user_password_resets')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now(), 'updated_at' => now()]);

            DB::table('user_password_resets')->insert([
                'user_id' => $user->id,
                'token_hash' => self::hash($token),
                'expires_at' => now()->addMinutes($ttl),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Listener (modul Notification) menulis baris delivery di transaksi yang sama.
            event(new PasswordResetRequested(
                $user->id,
                config('app.frontend_url').'/reset-password?token='.$token,
                $ttl,
                $invitation,
            ));
        });

        return $token;
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
