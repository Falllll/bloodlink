<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Notification\Domain\DevicePlatform;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mendaftarkan token push. Token unik GLOBAL: token yang sudah ada milik pengguna
 * lain DIPINDAHKAN ke pengguna ini -- perangkatnya memang berpindah tangan, dan
 * pesan untuk pemilik lama tidak boleh lagi mendarat di layar ini.
 */
final class RegisterDeviceToken
{
    public function handle(User $user, string $token, DevicePlatform $platform): DeviceToken
    {
        try {
            return $this->upsert($user, $token, $platform);
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran serentak untuk token BARU yang sama: lockForUpdate tidak
            // mengunci baris yang belum ada. Yang kalah menemukan baris pemenang pada
            // percobaan kedua dan memindahkannya seperti biasa.
            return $this->upsert($user, $token, $platform);
        }
    }

    private function upsert(User $user, string $token, DevicePlatform $platform): DeviceToken
    {
        return DB::transaction(function () use ($user, $token, $platform): DeviceToken {
            $device = DeviceToken::query()->where('token', $token)->lockForUpdate()->first();

            if ($device === null) {
                $device = new DeviceToken;
                $device->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'token' => $token,
                ]);
            }

            // Sama untuk ketiga kasus: baru, milik sendiri (aktif lagi), milik orang lain (pindah).
            $device->forceFill([
                'user_id' => $user->id,
                'platform' => $platform,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ])->save();

            return $device;
        });
    }
}
