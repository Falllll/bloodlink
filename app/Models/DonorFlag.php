<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Donor\Domain\DonorFlagReason;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sanksi administratif satu donor (kartu 430). BUKAN deferral medis.
 * donor_id, placed_by, lifted_by sengaja tidak fillable: kolom milik server.
 *
 * @property int $id
 * @property string $public_id
 * @property int $donor_id
 * @property DonorFlagReason $reason
 * @property string $note
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $placed_by
 * @property Carbon|null $lifted_at
 * @property int|null $lifted_by
 * @property string|null $lift_note
 */
final class DonorFlag extends Model
{
    use Auditable;

    protected $fillable = ['reason', 'note', 'starts_at', 'ends_at'];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => DonorFlagReason::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Donor, $this> */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /** Klausa sama dengan FlagDonor::whereActive dan DonorFlagWindow::isActiveOn. */
    public function isActive(): bool
    {
        return $this->lifted_at === null && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa donor_id numerik maupun placed_by/lifted_by numerik.
        return [
            'id' => $this->public_id,
            'donor_id' => $this->donor->public_id,
            'reason' => $this->reason->value,
            'note' => $this->note,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->isActive(),
            'lifted_at' => $this->lifted_at?->toIso8601String(),
            'lift_note' => $this->lift_note,
        ];
    }
}
