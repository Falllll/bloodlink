<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\StrictRequest;

final class RecordTransfusionRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'started_at' => ['required', 'date', 'before_or_equal:now'],
            // Sama dengan CHECK transfusions_time_order; database tetap penjaga terakhir.
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at', 'before_or_equal:now'],
            // Sama dengan CHECK transfusions_completion_shape: volume hanya untuk transfusi yang selesai.
            'volume_transfused_ml' => ['nullable', 'integer', 'min:1', 'max:65535', 'prohibited_if:completed_at,null'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
