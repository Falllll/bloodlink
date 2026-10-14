<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Satu unit yang dialokasikan untuk satu permintaan darah. Aktif selama
 * released_at null (partial unique index: satu alokasi aktif per unit).
 *
 * @property int $id
 * @property string $public_id
 * @property int $blood_request_id
 * @property int $blood_batch_id
 * @property int $allocated_by
 * @property Carbon $allocated_at
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 */
final class BloodRequestAllocation extends Model
{
    use Auditable;

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
            'allocated_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BloodRequest, $this> */
    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class);
    }

    /** @return BelongsTo<BloodBatch, $this> */
    public function bloodBatch(): BelongsTo
    {
        return $this->belongsTo(BloodBatch::class);
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal maupun allocated_by.
        return [
            'id' => $this->public_id,
            'blood_request_id' => $this->bloodRequest->public_id,
            'unit_id' => $this->bloodBatch->public_id,
            'allocated_at' => $this->allocated_at->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'release_reason' => $this->release_reason,
        ];
    }
}
