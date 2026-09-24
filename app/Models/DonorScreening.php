<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $screened_at
 */
final class DonorScreening extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    // public_id, donor_id, facility_id, screened_by, passed, findings, dan
    // screened_at sengaja di luar $fillable: semuanya ditentukan server,
    // ditulis lewat forceFill() seperti pola PlaceDeferral (Kartu 120).
    protected $fillable = [
        'haemoglobin_dg_dl', 'systolic_mmhg', 'diastolic_mmhg', 'pulse_bpm',
        'temperature_dc', 'weight_kg', 'planned_volume_ml', 'note',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'screened_at' => 'datetime',
            'passed' => 'boolean',
            'weight_kg' => 'decimal:2',
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

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'haemoglobin_g_dl' => round($this->haemoglobin_dg_dl / 10, 1),
            'systolic_mmhg' => $this->systolic_mmhg,
            'diastolic_mmhg' => $this->diastolic_mmhg,
            'pulse_bpm' => $this->pulse_bpm,
            'temperature_c' => round($this->temperature_dc / 10, 1),
            'weight_kg' => $this->weight_kg,
            'planned_volume_ml' => $this->planned_volume_ml,
            'passed' => $this->passed,
            'findings' => $this->findings,
            'screened_at' => $this->screened_at->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
