<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;

/** Satu tanggal LOKAL fasilitas tutup untuk appointment, sehari penuh (kartu 503). */
final class AppointmentClosureRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
