<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateFacilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // otorisasi dikerjakan Gate::authorize() di controller
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'sometimes', 'string', 'regex:/^[A-Z0-9-]{3,32}$/',
                Rule::unique('facilities', 'code')->ignore($this->route('facility')?->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(['hospital', 'blood_bank', 'donation_unit', 'mobile_unit'])],
            'address' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'province' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
        ];
    }
}
