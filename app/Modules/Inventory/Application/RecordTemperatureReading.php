<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\StorageLocation;
use App\Models\StorageTemperatureReading;
use App\Modules\Inventory\Domain\Events\StorageTemperatureOutOfRange;
use App\Modules\Inventory\Domain\ReadingSource;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pembacaan di luar rentang TIDAK pernah ditolak: ia sah dan justru wajib
 * tersimpan. Hasil penilaiannya disimpan di barisnya (is_out_of_range),
 * terhadap band yang berlaku saat ini, supaya revisi band kemudian tidak
 * mengubah arti pembacaan lama secara surut.
 */
final class RecordTemperatureReading
{
    public function handle(
        StorageLocation $location,
        string $readingCelsius,
        ReadingSource $source,
        ?int $recordedBy = null,
        ?string $note = null,
    ): StorageTemperatureReading {
        $reading = DB::transaction(function () use ($location, $readingCelsius, $source, $recordedBy, $note): StorageTemperatureReading {
            // Perbandingan di SQL, bukan di PHP: decimal:1 mengembalikan string.
            // BETWEEN inklusif di kedua ujung -- tepat di min/max masih di dalam rentang.
            // numeric(4,1), sama dengan kolomnya: yang dinilai adalah nilai yang tersimpan,
            // jadi 6.04 dinilai sebagai 6.0, bukan sebagai 6.04 lalu disimpan 6.0.
            $inRange = StorageLocation::query()
                ->whereKey($location->getKey())
                ->whereRaw('CAST(? AS numeric(4, 1)) BETWEEN temp_min_celsius AND temp_max_celsius', [$readingCelsius])
                ->exists();

            $reading = new StorageTemperatureReading([
                'reading_celsius' => $readingCelsius,
                'source' => $source,
                'note' => $note,
            ]);

            $reading->forceFill([
                'storage_location_id' => $location->id,
                'is_out_of_range' => ! $inRange,
                'recorded_by' => $recordedBy,
                'recorded_at' => now(),
            ])->save();

            return $reading;
        });

        if ($reading->is_out_of_range) {
            event(new StorageTemperatureOutOfRange(
                $location->id,
                (string) $reading->reading_celsius,
                $reading->recorded_at->toDateTimeImmutable()->format(DateTimeInterface::ATOM),
            ));
        }

        return $reading->setRelation('storageLocation', $location);
    }
}
