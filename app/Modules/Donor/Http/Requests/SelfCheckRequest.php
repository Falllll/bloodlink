<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class SelfCheckRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // weight_kg TIDAK boleh min:45 atau min:50. Ambang itu milik
            // BodyWeightRule; kalau ditegakkan di sini, NOT_ELIGIBLE berubah
            // diam-diam jadi 422.
            'date_of_birth' => ['required', 'date', 'before:today'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'weight_kg' => ['nullable', 'numeric', 'min:20', 'max:300'],
            'last_donation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'planned_volume_ml' => ['nullable', 'integer', 'in:350,450'],
        ];
    }
}
