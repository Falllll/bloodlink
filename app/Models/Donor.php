<?php

namespace App\Models;

use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donor extends Model implements FacilityScoped
{
    /** @use HasFactory<DonorFactory> */
    use HasFactory, ScopedToFacility, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'email',
        'blood_type',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'last_donation_date' => 'date',
            'deferred_until' => 'date',
            'is_deferred' => 'boolean',
            'weight_kg' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'registered_facility_id');
    }

    public function facilityColumn(): string
    {
        return 'registered_facility_id';
    }
}
