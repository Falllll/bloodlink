<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Donor\Domain\DeferralDurationUnit;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\DeferralType;
use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property DeferralType $type
 * @property DeferralDurationUnit|null $duration_unit
 * @property DeferralSource $source
 * @property Carbon $anchor_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $lifted_at
 */
final class Deferral extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    // facility_id & placed_by sengaja di luar $fillable: disetel server lewat
    // forceFill() di PlaceDeferral, sejalan dengan pola DonorConsent (Kartu 96).
    protected $fillable = [
        'donor_id', 'deferral_reason_id', 'type', 'anchor_at',
        'duration_value', 'duration_unit', 'ends_at', 'source', 'note', 'referral_note',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'type' => DeferralType::class,
            'duration_unit' => DeferralDurationUnit::class,
            'duration_value' => 'integer',
            'source' => DeferralSource::class,
            'anchor_at' => 'datetime',
            'ends_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function facilityColumn(): string
    {
        return 'facility_id';
    }

    /** @return BelongsTo<Donor, $this> */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /** @return BelongsTo<DeferralReason, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(DeferralReason::class, 'deferral_reason_id');
    }

    public function isActive(): bool
    {
        return $this->lifted_at === null && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
