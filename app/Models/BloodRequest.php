<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Request\Domain\RequestStatus;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Permintaan darah dari satu fasilitas. facility_id tidak fillable: ia diisi
 * server lewat assignFacility(), sama seperti BloodBatch.
 *
 * @property int $id
 * @property string $public_id
 * @property int $facility_id
 * @property string $patient_reference
 * @property BloodComponent $component
 * @property BloodGroup $blood_group
 * @property RhFactor $rh_factor
 * @property int $quantity
 * @property RequestUrgency $urgency
 * @property RequestStatus $status
 * @property Carbon $needed_by
 * @property string|null $note
 * @property int $requested_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
final class BloodRequest extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    protected $fillable = ['patient_reference', 'component', 'blood_group', 'rh_factor', 'quantity', 'urgency', 'needed_by', 'note'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'component' => BloodComponent::class,
            'blood_group' => BloodGroup::class,
            'rh_factor' => RhFactor::class,
            'urgency' => RequestUrgency::class,
            'status' => RequestStatus::class,
            'needed_by' => 'datetime',
            'cancelled_at' => 'datetime',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        // Tanpa id internal maupun requested_by.
        return [
            'id' => $this->public_id,
            'facility_id' => $this->facility->public_id,
            'patient_reference' => $this->patient_reference,
            'component' => $this->component->value,
            'blood_group' => $this->blood_group->value,
            'rh_factor' => $this->rh_factor->value,
            'quantity' => $this->quantity,
            'urgency' => $this->urgency->value,
            'status' => $this->status->value,
            'needed_by' => $this->needed_by->toIso8601String(),
            'note' => $this->note,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }
}
