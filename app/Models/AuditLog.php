<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array{before: array<string, mixed>, after: array<string, mixed>} $changes
 * @property Carbon $occurred_at
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'datetime'];
    }

    /**
     * Global hanya untuk pembaca tanpa fasilitas; sisanya dikunci ke fasilitasnya sendiri.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReadableBy(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->facilityId() === null) {
            return $query;
        }

        return $query->where('actor_facility_id', $user->facilityId());
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => (string) $this->id,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'actor_facility_id' => $this->actor_facility_id,
            'changes' => $this->getAttribute('changes'),
            'trace_id' => $this->trace_id,
            'ip' => $this->ip,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
