<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Donor\Domain\AppointmentStatus;
use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property AppointmentStatus $status
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $arrived_at
 * @property Carbon|null $screened_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $no_show_at
 * @property Carbon|null $cancelled_at
 */
final class Appointment extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    // status dan seluruh timestamp transisi sengaja di luar $fillable:
    // ditulis server-side lewat forceFill() di BookAppointment/TransitionAppointment,
    // sejalan dengan pola PlaceDeferral (Kartu 120).
    protected $fillable = ['scheduled_for', 'note'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'scheduled_for' => 'datetime',
            'arrived_at' => 'datetime',
            'screened_at' => 'datetime',
            'completed_at' => 'datetime',
            'no_show_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    /** @return HasMany<DonorScreening, $this> */
    public function screenings(): HasMany
    {
        return $this->hasMany(DonorScreening::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'donor_id' => $this->donor->public_id,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'status' => $this->status->value,
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'screened_at' => $this->screened_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'no_show_at' => $this->no_show_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'note' => $this->note,
        ];
    }
}
