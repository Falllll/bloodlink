<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Chat\Domain\MessageSenderKind;
use App\Shared\Database\Auditable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pesan dalam percakapan (kartu 495). body adalah DATA KESEHATAN: terenkripsi
 * di database (cast 'encrypted', pola Donor) dan tidak pernah masuk audit_logs.
 * Pesan hanya ditambah -- tidak ada jalur yang mengubah atau menghapus riwayat.
 *
 * @property int $id
 * @property string $public_id
 * @property int $conversation_id
 * @property MessageSenderKind $sender_kind
 * @property int|null $sender_user_id
 * @property string|null $body null = dikosongkan privacy:purge-expired
 */
final class Message extends Model
{
    use Auditable;

    // conversation_id, sender_user_id, dan public_id milik server.
    protected $fillable = ['sender_kind', 'body'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sender_kind' => MessageSenderKind::class,
            'body' => 'encrypted',
        ];
    }

    /**
     * body dikecualikan: audit menyimpan nilai atribut, dan ciphertext di audit_logs
     * berarti data kesehatan tinggal di tabel yang retensinya berbeda.
     *
     * @return list<string>
     */
    public function auditExcept(): array
    {
        return ['updated_at', 'body'];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'sender_kind' => $this->sender_kind->value,
            'body' => $this->body,
            'created_at' => $this->created_at?->format(DateTimeInterface::ATOM),
        ];
    }
}
