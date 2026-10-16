<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;

/**
 * Satu kantong yang benar-benar masuk ke pasien. Satu-ke-satu dengan alokasi
 * (unique index). Unit tetap ISSUED: transfusi dicatat di sini, bukan sebagai status.
 *
 * SENGAJA tanpa ScopedToFacility, sama dengan Crossmatch: cakupan fasilitas
 * datang lewat alokasi dan permintaannya.
 *
 * @property int $id
 * @property string $public_id
 * @property int $blood_request_allocation_id
 * @property int $recorded_by
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property int|null $volume_transfused_ml
 * @property string|null $note
 */
final class Transfusion extends Model
{
    use Auditable;

    protected $fillable = ['started_at', 'completed_at', 'volume_transfused_ml', 'note'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'volume_transfused_ml' => 'integer',
        ];
    }

    /** @return BelongsTo<BloodRequestAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(BloodRequestAllocation::class, 'blood_request_allocation_id');
    }

    /**
     * Rantai telusur: transfusi -> alokasi -> unit -> donasi -> donor. Dibaca lewat
     * relasi, bukan kolom salinan, supaya tetap benar setelah MergeDonors.
     *
     * @return HasOneThrough<BloodBatch, BloodRequestAllocation, $this>
     */
    public function batch(): HasOneThrough
    {
        return $this->hasOneThrough(
            BloodBatch::class,
            BloodRequestAllocation::class,
            'id',                            // blood_request_allocations.id
            'id',                            // blood_batches.id
            'blood_request_allocation_id',   // transfusions.blood_request_allocation_id
            'blood_batch_id',                // blood_request_allocations.blood_batch_id
        );
    }

    /** @return HasMany<TransfusionReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(TransfusionReaction::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal maupun recorded_by.
        return [
            'id' => $this->public_id,
            'allocation_id' => $this->allocation->public_id,
            'started_at' => $this->started_at->format(DateTimeInterface::ATOM),
            'completed_at' => $this->completed_at?->format(DateTimeInterface::ATOM),
            'volume_transfused_ml' => $this->volume_transfused_ml,
            'note' => $this->note,
        ];
    }
}
