<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;

final class BookAppointmentRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
