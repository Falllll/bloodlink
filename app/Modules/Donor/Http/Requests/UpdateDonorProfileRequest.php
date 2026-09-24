<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Models\Donor;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Validator;

final class UpdateDonorProfileRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'nik' => ['sometimes', 'required', 'digits:16'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Donor $donor */
                $donor = $this->route('donor');

                if ($this->has('nik') && $donor->nik !== null && $donor->nik !== $this->input('nik')) {
                    $validator->addFailure('nik', 'nik_not_changeable');
                }
            },
        ];
    }
}
