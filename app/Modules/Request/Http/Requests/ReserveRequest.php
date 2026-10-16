<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\StrictRequest;

final class ReserveRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // max:168 (satu minggu) adalah kewarasan instrumen, bukan aturan medis.
            'window_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ];
    }

    public function windowHours(): ?int
    {
        $hours = $this->validated('window_hours');

        return $hours === null ? null : (int) $hours;
    }
}
