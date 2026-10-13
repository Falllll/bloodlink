<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;

/**
 * Berbeda dari BookAppointmentRequest: scheduled_for WAJIB di sini.
 * scheduled_for null berarti walk-in, dan walk-in adalah pernyataan petugas.
 */
final class BookOwnAppointmentRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scheduled_for' => ['required', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
