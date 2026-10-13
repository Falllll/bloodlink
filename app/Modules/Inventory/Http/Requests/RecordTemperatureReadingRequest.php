<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\ReadingSource;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * is_out_of_range dan recorded_at sengaja tidak ada di rules(): keduanya
 * ditentukan server. Pembacaan di luar band lokasi BUKAN 422 -- ia tersimpan.
 */
final class RecordTemperatureReadingRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Kewarasan instrumen, BUKAN band medis lokasi.
            'reading_celsius' => ['required', 'numeric', 'between:-80,40'],
            'source' => ['required', Rule::enum(ReadingSource::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function source(): ReadingSource
    {
        return ReadingSource::from($this->validated('source'));
    }
}
