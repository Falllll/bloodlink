<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Modules\Donor\Domain\AppointmentStatus;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class CancelOwnAppointmentRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Sengaja bukan Rule::enum(AppointmentStatus::class): donor hanya boleh membatalkan.
            'status' => ['required', Rule::in([AppointmentStatus::CANCELLED->value])],
        ];
    }

    public function status(): AppointmentStatus
    {
        return AppointmentStatus::from($this->validated('status'));
    }
}
