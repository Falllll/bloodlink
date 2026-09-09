<?php

namespace App\Models;

use Database\Factories\BloodBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BloodBatch extends Model
{
    /** @use HasFactory<BloodBatchFactory> */
    use HasFactory;

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

    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }
}
