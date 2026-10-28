<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Notification\Domain\NotificationCategory;
use InvalidArgumentException;

/**
 * Menolak atau kembali menerima satu kategori (kartu 488). Idempoten: menolak dua
 * kali tidak memajukan opted_out_at -- yang dicatat adalah kapan ia MULAI menolak.
 * Kembali ikut-serta mengosongkan opted_out_at; riwayatnya di audit_logs.
 *
 * Konfirmasi keluar dari daftar panggilan darurat ditagih di lapisan HTTP
 * (UpdateNotificationPreferenceRequest), bukan di sini.
 */
final class UpdateNotificationPreference
{
    public function handle(User $user, NotificationCategory $category, bool $enabled): NotificationPreference
    {
        if (! $category->isOptional()) {
            throw new InvalidArgumentException("Notification category [{$category->value}] cannot be opted out of.");
        }

        $preference = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('category', $category->value)
            ->first() ?? (new NotificationPreference(['category' => $category]))->forceFill(['user_id' => $user->id]);

        $preference->forceFill(['opted_out_at' => $enabled ? null : ($preference->opted_out_at ?? now())])->save();

        return $preference;
    }
}
