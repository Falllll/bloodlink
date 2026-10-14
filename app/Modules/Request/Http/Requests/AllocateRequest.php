<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\StrictRequest;

final class AllocateRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Opsional: tanpa max, sisa kuantitas permintaan yang dialokasikan.
            'max' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function max(): ?int
    {
        $max = $this->validated('max');

        return $max === null ? null : (int) $max;
    }
}
