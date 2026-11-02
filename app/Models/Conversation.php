<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Chat\Domain\ConversationStatus;
use App\Modules\Chat\Domain\EscalationReason;
use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu percakapan pengunjung/donor dengan fasilitas (kartu 495). Semua kolom milik
 * server: status hanya berpindah lewat action Chat, facility_id hanya diisi saat
 * eskalasi, dan token pengunjung disimpan sebagai HMAC -- tokennya sendiri keluar
 * SEKALI di respons 201.
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $facility_id
 * @property ConversationStatus $status
 * @property string|null $visitor_token_hash
 * @property Carbon|null $visitor_expires_at
 * @property Carbon|null $escalated_at
 * @property EscalationReason|null $escalation_reason
 * @property int|null $assigned_user_id
 * @property Carbon|null $closed_at
 */
final class Conversation extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    protected $fillable = [];

    protected $hidden = ['id', 'visitor_token_hash'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'escalation_reason' => EscalationReason::class,
            'visitor_expires_at' => 'datetime',
            'escalated_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Hash token pengunjung tidak masuk audit_logs: ia pengidentifikasi, dan audit
     * menyimpan nilai atribut apa adanya.
     *
     * @return list<string>
     */
    public function auditExcept(): array
    {
        return ['updated_at', 'visitor_token_hash'];
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Blind index token pengunjung. Pola persis Donor::phoneHash(): HMAC dengan
     * app.key, jadi bocornya isi tabel tidak memberi token yang bisa dipakai.
     */
    public static function visitorTokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    /**
     * Tanpa visitor_token_hash, tanpa id numerik, tanpa id petugas numerik.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'facility_id' => $this->facility?->public_id,
            'status' => $this->status->value,
            'escalated_at' => $this->escalated_at?->format(DateTimeInterface::ATOM),
            'escalation_reason' => $this->escalation_reason?->value,
            'assigned_user_id' => $this->assignee?->public_id,
            'closed_at' => $this->closed_at?->format(DateTimeInterface::ATOM),
            'created_at' => $this->created_at?->format(DateTimeInterface::ATOM),
        ];
    }
}
