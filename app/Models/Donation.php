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
 * @property int $volume_ml
 * @property Carbon $started_at
 * @property Carbon $completed_at
 */
final class Donation extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    // public_id, donor_id, facility_id, appointment_id, screening_id, dan
    // collected_by sengaja di luar $fillable: ditentukan server lewat
    // forceFill() di RecordDonation, sejalan dengan pola PlaceDeferral (Kartu 120).
    protected $fillable = ['volume_ml', 'started_at', 'completed_at', 'note'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<DonorScreening, $this> */
    public function screening(): BelongsTo
    {
        return $this->belongsTo(DonorScreening::class, 'screening_id');
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'donor_id' => $this->donor->public_id,
            'appointment_id' => $this->appointment->public_id,
            'screening_id' => $this->screening?->public_id,
            'volume_ml' => $this->volume_ml,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
