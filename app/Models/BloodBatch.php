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
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BatchStatus::class,
            'collected_at' => 'datetime',
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
}
