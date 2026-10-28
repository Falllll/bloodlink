<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Penutupan appointment satu fasilitas pada satu TANGGAL LOKAL, sehari penuh (kartu 503).
 *
 * @property int $id
 * @property int $facility_id
 * @property Carbon $closed_on
 * @property string $reason
 */
final class FacilityAppointmentClosure extends Model
{
    protected $fillable = ['closed_on', 'reason'];

    protected $hidden = ['id', 'facility_id', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['closed_on' => 'date'];
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'date' => $this->closed_on->toDateString(),
            'reason' => $this->reason,
        ];
    }
}
