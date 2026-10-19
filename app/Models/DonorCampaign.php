<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Request\Domain\CampaignEndReason;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Kampanye panggilan donor berjenjang untuk satu permintaan (kartu 400). Aktif
 * selama ended_at null; paling banyak satu aktif per permintaan (index parsial).
 *
 * @property int $id
 * @property string $public_id
 * @property int $blood_request_id
 * @property int $started_by
 * @property int $current_radius_meters
 * @property Carbon $stage_entered_at
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property CampaignEndReason|null $end_reason
 */
final class DonorCampaign extends Model
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
            'current_radius_meters' => 'integer',
            'stage_entered_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => CampaignEndReason::class,
        ];
    }

    /** @return BelongsTo<BloodRequest, $this> */
    public function bloodRequest(): BelongsTo
    {
        return $this->belongsTo(BloodRequest::class);
    }

    /** @return HasMany<DonorCampaignContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(DonorCampaignContact::class);
    }

    /**
     * @param  Builder<DonorCampaign>  $query
     * @return Builder<DonorCampaign>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
