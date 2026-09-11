<?php

namespace App\Models;

use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Database\Factories\BloodBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodBatch extends Model implements FacilityScoped
{
    /** @use HasFactory<BloodBatchFactory> */
    use HasFactory, ScopedToFacility;

    protected $fillable = [
        'blood_type',
        'volume_ml',
        'donor_id',
        'facility_id',
        'collected_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
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
}
