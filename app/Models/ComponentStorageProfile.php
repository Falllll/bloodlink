<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\ShelfLifeUnit;
use App\Shared\Database\Auditable;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $shelf_life_value
 * @property ShelfLifeUnit $shelf_life_unit
 * @property bool $is_default
 */
final class ComponentStorageProfile extends Model
{
    use Auditable;

    protected $fillable = [
        'component_type_id', 'storage_temp_min_celsius', 'storage_temp_max_celsius',
        'shelf_life_value', 'shelf_life_unit', 'is_default', 'source_reference',
    ];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return [
            'storage_temp_min_celsius' => 'decimal:1',
            'storage_temp_max_celsius' => 'decimal:1',
            'shelf_life_value' => 'integer',
            'shelf_life_unit' => ShelfLifeUnit::class,
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<ComponentType, $this> */
    public function componentType(): BelongsTo
    {
        return $this->belongsTo(ComponentType::class);
    }

    public function expiryFrom(DateTimeImmutable $collectedAt): DateTimeImmutable
    {
        $expiry = $collectedAt->add(new DateInterval(
            $this->shelf_life_unit->toDateIntervalSpec($this->shelf_life_value)
        ));

        // P3M dari 31 Jan meluap ke 1 Mei. Untuk kedaluwarsa, luapan berarti unit
        // hidup lebih lama dari seharusnya — tarik ke hari terakhir bulan tujuan.
        if ($this->shelf_life_unit === ShelfLifeUnit::MONTHS && $expiry->format('j') !== $collectedAt->format('j')) {
            $expiry = $expiry->modify('last day of previous month');
        }

        return $expiry;
    }
}
