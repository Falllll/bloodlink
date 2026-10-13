<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Models\User;
use App\Modules\Inventory\Domain\StorageKind;
use App\Modules\Inventory\Domain\StoragePurpose;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * facility_id sengaja tidak ada di rules(): lokasi selalu milik fasilitas
 * pemegang token, dan StrictRequest membalas key itu 422 not_allowed.
 */
final class StoreStorageLocationRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->user();
        $facilityId = $user instanceof User ? $user->facilityId() : null;

        return [
            // Unik per fasilitas, sama dengan storage_locations_code_unique -- 422, bukan 500.
            'code' => ['required', 'string', 'max:32', Rule::unique('storage_locations', 'code')->where('facility_id', $facilityId)],
            'label' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(StorageKind::class)],
            'purpose' => ['required', Rule::enum(StoragePurpose::class)],
            // Kewarasan instrumen, BUKAN ambang medis: band medisnya hidup di barisnya sendiri.
            'temp_min_celsius' => ['required', 'numeric', 'between:-80,40'],
            'temp_max_celsius' => ['required', 'numeric', 'between:-80,40', 'gte:temp_min_celsius'],
        ];
    }
}
