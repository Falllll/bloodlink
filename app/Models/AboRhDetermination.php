<?php

declare(strict_types=1);

namespace App\Models;

use App\Shared\Database\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $determined_at
 */
final class AboRhDetermination extends Model
{
    use Auditable;

    // public_id, blood_batch_id, donation_id, dan recorded_by ditentukan server
    // lewat forceFill() di RecordAboRhDetermination.
    protected $fillable = ['blood_group', 'rh_factor', 'determined_at'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['determined_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->public_id,
            'blood_group' => $this->blood_group,
            'rh_factor' => $this->rh_factor,
            'determined_at' => $this->determined_at->toIso8601String(),
        ];
    }
}
