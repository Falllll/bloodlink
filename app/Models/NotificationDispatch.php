<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu kejadian notifikasi untuk satu penerima (kartu 489): induk dari baris
 * notification_deliveries per alamat per kanal. Ditulis HANYA oleh DispatchNotification,
 * dibaca riwayat GET /me/notifications.
 *
 * SENGAJA tanpa isi pesan: klien merender teks dari katalog lokal menurut template_code.
 * SENGAJA tanpa Auditable, sama seperti NotificationDelivery.
 *
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $template_code
 * @property string $locale
 * @property DateTimeImmutable $dispatched_at
 */
final class NotificationDispatch extends Model
{
    // Semua kolom milik server.
    protected $fillable = [];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<NotificationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    /**
     * Untuk PENERIMANYA sendiri. Per delivery hanya kanal, status, dan waktu selesai:
     * failure_code dan failure_detail tidak keluar -- galat SMTP mentah bukan informasi
     * untuk orang, dan kadang memuat alamat server. Alamat dan token tidak pernah ada.
     *
     * @return array<string, mixed>
     */
    public function toRecipientArray(): array
    {
        $deliveries = $this->deliveries->sortBy('id')->values();

        return [
            'id' => $this->public_id,
            'template_code' => $this->template_code,
            'locale' => $this->locale,
            'dispatched_at' => $this->dispatched_at->format(DateTimeInterface::ATOM),
            'outcome' => $this->outcome(),
            'deliveries' => $deliveries->map(fn (NotificationDelivery $d): array => [
                'channel' => $d->channel->value,
                'status' => $d->status->value,
                'settled_at' => $d->settled_at?->format(DateTimeInterface::ATOM),
            ])->all(),
        ];
    }

    /**
     * Satu jawaban per kejadian, supaya klien tidak menyimpulkannya sendiri dari
     * beberapa baris: terkirim lewat SATU kanal saja sudah 'delivered' ("kamu
     * melewatkannya"); 'failed' hanya bila tidak ada yang terkirim maupun masih
     * menunggu ("kamu tidak pernah dapat"); 'suppressed' bila semuanya ditolak
     * preferensi penerima (kartu 488).
     */
    private function outcome(): string
    {
        $statuses = $this->deliveries->map(fn (NotificationDelivery $d): string => $d->status->value)->unique();

        return match (true) {
            $statuses->contains('sent') => 'delivered',
            $statuses->contains('pending') => 'pending',
            $statuses->contains('failed') => 'failed',
            default => 'suppressed',
        };
    }
}
