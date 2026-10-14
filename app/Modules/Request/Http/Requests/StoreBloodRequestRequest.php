<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * facility_id dan status sengaja tidak ada di rules(): StrictRequest membalasnya
 * 422 not_allowed. Fasilitas selalu dari token, status selalu lahir open.
 */
final class StoreBloodRequestRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'patient_reference' => ['required', 'string', 'max:64'],
            'component' => ['required', Rule::enum(BloodComponent::class)],
            'blood_group' => ['required', Rule::enum(BloodGroup::class)],
            'rh_factor' => ['required', Rule::enum(RhFactor::class)],
            // max:20 adalah kewarasan instrumen, pilihan operasional -- BUKAN aturan
            // medis. 500 kantong dari satu rumah sakit hampir pasti salah ketik.
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'urgency' => ['required', Rule::enum(RequestUrgency::class)],
            // Wajib dan di masa depan: tanpa tenggat permintaan tidak bisa diurutkan.
            'needed_by' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
