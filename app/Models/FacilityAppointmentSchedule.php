<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu jendela pada satu hari dalam pola mingguan appointment fasilitas (kartu 503).
 * Slot konkret dihitung dari sini, tidak pernah disimpan per hari. Menit = jam dinding
 * LOKAL fasilitas (facilities.timezone), bukan UTC.
 *
 * @property int $id
 * @property int $facility_id
 * @property int $weekday
 * @property int $opens_minute
 * @property int $closes_minute
 * @property int $slot_minutes
 * @property int $capacity
 */
final class FacilityAppointmentSchedule extends Model
{
    protected $fillable = ['weekday', 'opens_minute', 'closes_minute', 'slot_minutes', 'capacity'];

    protected $hidden = ['id', 'facility_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'opens_minute' => 'integer',
            'closes_minute' => 'integer',
            'slot_minutes' => 'integer',
            'capacity' => 'integer',
        ];
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'weekday' => $this->weekday,
            'opens_at' => self::clock($this->opens_minute),
            'closes_at' => self::clock($this->closes_minute),
            'slot_minutes' => $this->slot_minutes,
            'capacity' => $this->capacity,
        ];
    }

    public static function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
