<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Modules\Donor\Application\AppointmentSlots;
use App\Shared\Http\StrictRequest;

/** `from` adalah tanggal LOKAL fasilitas, bukan tanggal UTC (kartu 503). */
final class AppointmentSlotsRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,'.AppointmentSlots::MAX_DAYS],
        ];
    }
}
