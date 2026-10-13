<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Inventory\Domain\ReadingSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $storage_location_id
 * @property string $reading_celsius
 * @property bool $is_out_of_range
 * @property ReadingSource $source
 * @property int|null $recorded_by
 * @property string|null $note
 * @property Carbon $recorded_at
 */
final class StorageTemperatureReading extends Model
{
    protected $fillable = ['reading_celsius', 'source', 'note'];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reading_celsius' => 'decimal:1',
            'is_out_of_range' => 'boolean',
            'source' => ReadingSource::class,
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StorageLocation, $this> */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'storage_location_id' => $this->storageLocation->public_id,
            'reading_celsius' => (float) $this->reading_celsius,
            'is_out_of_range' => $this->is_out_of_range,
            'source' => $this->source->value,
            'note' => $this->note,
            'recorded_at' => $this->recorded_at->toAtomString(),
        ];
    }
}
