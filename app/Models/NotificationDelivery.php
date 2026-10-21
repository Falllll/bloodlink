<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\NotificationChannel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu pengiriman ke satu alamat lewat satu kanal (kartu 460). Ditulis berstatus
 * pending SEBELUM dikirim. Alamat dan isi pesan yang sudah dirender SENGAJA tidak
 * disimpan; variables (bahan render) dienkripsi dan dihapus begitu terkirim.
 *
 * SENGAJA tanpa Auditable: audit_logs menyimpan nilai atribut, termasuk variables.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property int|null $device_token_id
 * @property string $template_code
 * @property string $locale
 * @property array<string, string|int>|null $variables
 * @property NotificationChannel $channel
 * @property DeliveryStatus $status
 * @property string|null $failure_code
 * @property string|null $failure_detail
 * @property Carbon $queued_at
 * @property Carbon|null $settled_at
 * @property int $attempts
 * @property Carbon|null $last_attempt_at
 */
final class NotificationDelivery extends Model
{
    // user_id, device_token_id, status, dan variables adalah kolom milik server.
    protected $fillable = ['template_code', 'channel'];

    protected $hidden = ['id', 'variables'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'variables' => 'encrypted:array',
            'queued_at' => 'datetime',
            'settled_at' => 'datetime',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<DeviceToken, $this> */
    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(DeviceToken::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'template_code' => $this->template_code,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code,
            'attempts' => $this->attempts,
            'queued_at' => $this->queued_at->format(DateTimeInterface::ATOM),
            'settled_at' => $this->settled_at?->format(DateTimeInterface::ATOM),
        ];
    }
}
