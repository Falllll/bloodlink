<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreFacilityRequest extends FormRequest
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
            'code' => ['required', 'string', 'regex:/^[A-Z0-9-]{3,32}$/', 'unique:facilities,code'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['hospital', 'blood_bank', 'donation_unit', 'mobile_unit'])],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => [
                Rule::requiredIf(in_array($this->input('type'), ['hospital', 'blood_bank'], true)),
                'numeric', 'between:-90,90',
            ],
            'longitude' => [
                Rule::requiredIf(in_array($this->input('type'), ['hospital', 'blood_bank'], true)),
                'numeric', 'between:-180,180',
            ],
        ];
    }
}
