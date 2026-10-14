<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * expires_at, status, storage_profile_id, dan facility_id sengaja tidak ada di
 * rules(): StrictRequest membalasnya 422 not_allowed, bukan membuangnya diam-diam.
 */
final class StoreBloodBatchRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'component' => ['required', Rule::enum(BloodComponent::class)],
            'blood_group' => ['nullable', Rule::enum(BloodGroup::class)],
            'rh_factor' => ['nullable', Rule::enum(RhFactor::class)],
            'volume_ml' => ['required', 'integer', 'min:1'],
            'hemoglobin_g_dl' => ['nullable', 'numeric', 'min:0'],
            'collected_at' => ['required', 'date', 'before_or_equal:now'],
            // Suhu tempat unit benar-benar disimpan; profil -- dan kedaluwarsanya -- dipilih dari sini.
            'storage_temperature_c' => ['required', 'numeric'],
            'donor_id' => ['nullable', 'uuid', Rule::exists('donors', 'public_id')],
        ];
    }
}
