<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class UpdateDonorHealthStatusRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // min:20/max:300 adalah batas kewarasan fisik, BUKAN ambang kelayakan
            // §3.2 (45/50 kg). Jangan pernah menulis min:45 atau min:50 di sini.
            'weight_kg' => ['sometimes', 'required', 'numeric', 'min:20', 'max:300'],
            'blood_group' => ['sometimes', 'nullable', Rule::in(['A', 'B', 'AB', 'O'])],
            'rh_factor' => ['sometimes', 'nullable', Rule::in(['positive', 'negative'])],
        ];
    }
}
