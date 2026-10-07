<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\TtiTestType;
use Illuminate\Database\Eloquent\Collection;

/**
 * Panel = union: seluruh uji wajib (§6.1) SELALU ikut, ditambah uji regional
 * (§6.2) yang di-opt-in fasilitas lewat facility_tti_panels. Tidak ada jalan
 * untuk mengeluarkan uji wajib dari panel.
 */
final class ResolveTtiPanel
{
    private const string JURISDICTION = 'WHO';

    /** @return Collection<int, TtiTestType> */
    public function forFacility(int $facilityId): Collection
    {
        return TtiTestType::query()
            ->where('jurisdiction', self::JURISDICTION)
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('requirement', 'mandatory')
                ->orWhereIn('id', fn ($optIn) => $optIn
                    ->select('tti_test_type_id')
                    ->from('facility_tti_panels')
                    ->where('facility_id', $facilityId)))
            ->orderBy('id')
            ->get();
    }
}
