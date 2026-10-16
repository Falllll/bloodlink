<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Notification\Domain\DevicePlatform;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token push satu perangkat. Unik GLOBAL: pendaftaran ulang memindahkan
 * kepemilikan. Dicabut lewat revoked_at, tidak pernah dihapus.
 *
 * SENGAJA tanpa Auditable: token adalah kredensial pengiriman, dan audit_logs
 * menyimpan nilai atribut.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $token
 * @property DevicePlatform $platform
 * @property DateTimeImmutable $last_seen_at
 * @property DateTimeImmutable|null $revoked_at
 */
final class DeviceToken extends Model
{
    protected $fillable = ['platform'];

    // Token tidak pernah keluar ke API maupun serialisasi model.
    protected $hidden = ['id', 'token'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'platform' => DevicePlatform::class,
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Yang boleh dituju pengiriman (kartu 460): token yang belum dicabut, milik akun
     * yang masih ada. User memakai SoftDeletes, jadi cascadeOnDelete hanya berlaku
     * saat forceDelete -- whereHas menyaring akun yang di-soft-delete.
     *
     * @param  Builder<DeviceToken>  $query
     * @return Builder<DeviceToken>
     */
    public function scopeDeliverable(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->whereHas('user');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'platform' => $this->platform->value,
            'last_seen_at' => $this->last_seen_at->format(DateTimeInterface::ATOM),
            'revoked_at' => $this->revoked_at?->format(DateTimeInterface::ATOM),
        ];
    }
}
