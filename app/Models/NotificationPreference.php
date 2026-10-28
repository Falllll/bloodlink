<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Notification\Domain\NotificationCategory;
use App\Shared\Database\Auditable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Preferensi satu pengguna untuk satu kategori yang boleh ditolak (kartu 488).
 * Ketiadaan baris = ikut-serta; opted_out_at terisi = menolak. Ditulis HANYA lewat
 * UpdateNotificationPreference, ditegakkan HANYA di DispatchNotification.
 *
 * @property int $id
 * @property int $user_id
 * @property NotificationCategory $category
 * @property DateTimeImmutable|null $opted_out_at
 */
final class NotificationPreference extends Model
{
    use Auditable;

    // user_id dan opted_out_at adalah kolom milik server.
    protected $fillable = ['category'];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'opted_out_at' => 'immutable_datetime',
        ];
    }

    /**
     * Penolak panggilan darurat: efektifnya keluar dari daftar panggilan
     * (EloquentDonorDirectory::reachability). Scope di sini, bukan di modul Donor,
     * supaya modul lain tidak perlu menyebut kategorinya.
     *
     * @param  Builder<NotificationPreference>  $query
     * @return Builder<NotificationPreference>
     */
    public function scopeOptedOutOfEmergencyCalls(Builder $query): Builder
    {
        return $query->where('category', NotificationCategory::EMERGENCY_CALL->value)->whereNotNull('opted_out_at');
    }
}
