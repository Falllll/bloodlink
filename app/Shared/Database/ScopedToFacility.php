<?php

declare(strict_types=1);

namespace App\Shared\Database;

use App\Shared\Auth\FacilityMember;
use Illuminate\Database\Eloquent\Builder;

trait ScopedToFacility
{
    public function facilityColumn(): string
    {
        return 'facility_id';
    }

    public function ownerFacilityId(): ?int
    {
        $value = $this->getAttribute($this->facilityColumn());

        return $value === null ? null : (int) $value;
    }

    /**
     * Batasi query ke fasilitas yang boleh dilihat $user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?FacilityMember $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isGlobalOperator()) {
            return $query;
        }

        if ($user->facilityId() === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($this->facilityColumn(), $user->facilityId());
    }
}
