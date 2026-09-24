<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;

final class RecordDonationRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // 100-600 ml adalah kewarasan alat, BUKAN aturan medis. Rujukan §7
        // mencatat volume donasi standar belum diverifikasi, jadi 350/450
        // sengaja tidak ditegakkan di sini.
        return [
            'volume_ml' => ['required', 'integer', 'min:100', 'max:600'],
            'started_at' => ['required', 'date'],
            'completed_at' => ['required', 'date', 'after_or_equal:started_at'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
