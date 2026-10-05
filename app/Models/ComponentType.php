<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\BloodComponent;
use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BloodComponent $code
 * @property bool $is_frozen
 * @property bool $requires_agitation
 * @property bool $is_active
 */
final class ComponentType extends Model
{
    use Auditable;

    protected $fillable = [
        'jurisdiction', 'code', 'label', 'is_frozen', 'requires_agitation',
        'post_issue_window_minutes', 'post_thaw_window_hours',
        'post_thaw_storage_min_celsius', 'post_thaw_storage_max_celsius',
        'source_reference', 'is_active',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'code' => BloodComponent::class,
            'is_frozen' => 'boolean',
            'requires_agitation' => 'boolean',
            'is_active' => 'boolean',
            'post_issue_window_minutes' => 'integer',
            'post_thaw_window_hours' => 'integer',
            'post_thaw_storage_min_celsius' => 'decimal:1',
            'post_thaw_storage_max_celsius' => 'decimal:1',
        ];
    }

    /** @return HasMany<ComponentStorageProfile, $this> */
    public function storageProfiles(): HasMany
    {
        return $this->hasMany(ComponentStorageProfile::class);
    }

    public function defaultStorageProfile(): ?ComponentStorageProfile
    {
        return $this->storageProfiles()->where('is_default', true)->first();
    }
}
