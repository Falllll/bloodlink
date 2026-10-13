<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\StorageKind;
use App\Modules\Inventory\Domain\StoragePurpose;
use App\Shared\Database\Auditable;
use App\Shared\Database\FacilityScoped;
use App\Shared\Database\ScopedToFacility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lemari/freezer/agitator yang nyata di satu fasilitas, dengan band suhunya sendiri.
 *
 * @property int $id
 * @property string $public_id
 * @property int $facility_id
 * @property string $code
 * @property string $label
 * @property StorageKind $kind
 * @property StoragePurpose $purpose
 * @property string $temp_min_celsius
 * @property string $temp_max_celsius
 * @property bool $is_active
 */
final class StorageLocation extends Model implements FacilityScoped
{
    use Auditable, ScopedToFacility;

    protected $fillable = ['code', 'label', 'kind', 'purpose', 'temp_min_celsius', 'temp_max_celsius', 'is_active'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => StorageKind::class,
            'purpose' => StoragePurpose::class,
            // decimal:1 mengembalikan STRING: jangan dibandingkan di PHP.
            'temp_min_celsius' => 'decimal:1',
            'temp_max_celsius' => 'decimal:1',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Facility, $this> */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** @return HasMany<StorageTemperatureReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(StorageTemperatureReading::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'code' => $this->code,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'purpose' => $this->purpose->value,
            // Cast eksplisit: tanpa ini JSON-nya "4.0", bukan 4.
            'temp_min_celsius' => (float) $this->temp_min_celsius,
            'temp_max_celsius' => (float) $this->temp_max_celsius,
            'is_active' => $this->is_active,
        ];
    }
}
