<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\BatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu peristiwa status unit. Append-only lewat trigger
 * blood_batch_events_no_change; sengaja TANPA Auditable -- mengaudit tabel
 * audit adalah pemborosan.
 *
 * @property int $id
 * @property int $blood_batch_id
 * @property string $batch_public_id
 * @property int $facility_id
 * @property BatchStatus|null $from_status
 * @property BatchStatus $to_status
 * @property string|null $reason
 * @property int|null $actor_id
 * @property int|null $actor_facility_id
 * @property string|null $trace_id
 * @property Carbon $occurred_at
 */
final class BloodBatchEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => BatchStatus::class,
            'to_status' => BatchStatus::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BloodBatch, $this> */
    public function bloodBatch(): BelongsTo
    {
        return $this->belongsTo(BloodBatch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal, actor_id, maupun actor_facility_id.
        return [
            'batch_public_id' => $this->batch_public_id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'reason' => $this->reason,
            'occurred_at' => $this->occurred_at->toAtomString(),
        ];
    }
}
