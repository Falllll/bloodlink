<?php

namespace App\Models;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Database\Factories\BloodBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property BatchStatus $status
 * @property Carbon $collected_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $released_at
 */
class BloodBatch extends Model implements FacilityScoped
{
    /** @use HasFactory<BloodBatchFactory> */
    use Auditable, HasFactory, ScopedToFacility;

    protected $fillable = [
        'component',
        'blood_group',
        'rh_factor',
        'volume_ml',
        'hemoglobin_g_dl',
        'donor_id',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'collected_at' => 'datetime',
            'separated_at' => 'datetime',
            'released_at' => 'datetime',
            'separated_volume_ml' => 'integer',
            'expires_at' => 'datetime',
            'hemoglobin_g_dl' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class);
    }

    /**
     * Profil yang benar-benar ditempati unit -- asal-usul expires_at.
     *
     * @return BelongsTo<ComponentStorageProfile, $this>
     */
    public function storageProfile(): BelongsTo
    {
        return $this->belongsTo(ComponentStorageProfile::class, 'storage_profile_id');
    }

    /**
     * Look-back §6.3, arah turunan -> induk.
     *
     * @return BelongsTo<BloodBatch, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_unit_id');
    }

    /**
     * Look-back §6.3, arah induk -> turunan.
     *
     * @return HasMany<BloodBatch, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_unit_id');
    }

    /** @return HasMany<TtiTestResult, $this> */
    public function ttiTestResults(): HasMany
    {
        return $this->hasMany(TtiTestResult::class);
    }

    /** @return HasOne<AboRhDetermination, $this> */
    public function aboRhDetermination(): HasOne
    {
        return $this->hasOne(AboRhDetermination::class);
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'batch_number' => $this->batch_number,
            'facility_id' => $this->facility->public_id,
            'donor_id' => $this->donor?->public_id,
            'component' => $this->component,
            'blood_group' => $this->blood_group,
            'rh_factor' => $this->rh_factor,
            'volume_ml' => $this->volume_ml,
            'status' => $this->status->value,
            'parent_id' => $this->parent?->public_id,
            'collected_at' => $this->collected_at->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'storage_profile' => $this->storageProfile === null ? null : [
                'storage_temp_min_celsius' => (float) $this->storageProfile->storage_temp_min_celsius,
                'storage_temp_max_celsius' => (float) $this->storageProfile->storage_temp_max_celsius,
                'shelf_life_value' => $this->storageProfile->shelf_life_value,
                'shelf_life_unit' => $this->storageProfile->shelf_life_unit->value,
            ],
            'discard_reason' => $this->discard_reason,
        ];
    }
}
