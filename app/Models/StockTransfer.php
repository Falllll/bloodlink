<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\TransferStatus;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perpindahan satu unit antar fasilitas. Baris transfer terbuka -- bukan status
 * unit -- yang menahan unit dari alokasi ganda selama di jalan.
 *
 * SENGAJA tanpa ScopedToFacility: transfer punya DUA kolom fasilitas, sedangkan
 * trait itu mengasumsikan satu facility_id. Penyaringannya eksplisit di controller.
 *
 * @property int $id
 * @property string $public_id
 * @property int $blood_batch_id
 * @property int $from_facility_id
 * @property int $to_facility_id
 * @property TransferStatus $status
 * @property string|null $reason
 * @property string|null $decision_note
 * @property int $requested_by
 * @property int|null $decided_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $received_at
 * @property Carbon|null $cancelled_at
 */
final class StockTransfer extends Model
{
    use Auditable;

    protected $fillable = ['reason'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BloodBatch, $this> */
    public function bloodBatch(): BelongsTo
    {
        return $this->belongsTo(BloodBatch::class);
    }

    /** @return BelongsTo<Facility, $this> */
    public function fromFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'from_facility_id');
    }

    /** @return BelongsTo<Facility, $this> */
    public function toFacility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'to_facility_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal, requested_by, maupun decided_by.
        return [
            'id' => $this->public_id,
            'unit_id' => $this->bloodBatch->public_id,
            'from_facility_id' => $this->fromFacility->public_id,
            'to_facility_id' => $this->toFacility->public_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
