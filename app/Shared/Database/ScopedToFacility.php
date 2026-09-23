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

    /**
     * Setel pemilik fasilitas dari sisi server. Satu-satunya jalan yang sah.
     * Tidak menyimpan; pemanggil yang memutuskan kapan save() (mis. dalam transaksi).
     */
    public function assignFacility(int $facilityId): static
    {
        return $this->forceFill([$this->facilityColumn() => $facilityId]);
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

        if ($user->facilityId() !== null) {
            return $query->where($this->facilityColumn(), $user->facilityId());
        }

        if ($user->isGlobalOperator()) {
            return $query;
        }

        return $query->whereRaw('1 = 0');
    }
}
