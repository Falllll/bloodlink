<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class ReportTransfusionReactionRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Waktu KEJADIAN, bukan waktu laporan: reaksi tertunda dilaporkan berjam-jam kemudian.
            'observed_at' => ['required', 'date', 'before_or_equal:now'],
            'reaction_type_code' => ['nullable', 'string', Rule::exists('transfusion_reaction_types', 'code')->where('is_active', true)],
            'description' => ['required', 'string', 'max:5000'],
        ];
    }
}
