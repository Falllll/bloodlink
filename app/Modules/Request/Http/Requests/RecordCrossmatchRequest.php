<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Modules\Request\Domain\CrossmatchResult;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class RecordCrossmatchRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'result' => ['required', Rule::enum(CrossmatchResult::class)],
            // Teks bebas untuk jejak audit -- SENGAJA bukan enum: daftar metode adalah
            // materi medis, dan sistem tidak pernah memutuskan berdasarkan metode.
            'method' => ['nullable', 'string', 'max:120'],
            // Wajib untuk hasil yang gagal, sama dengan CHECK crossmatches_failure_needs_note.
            'note' => ['nullable', 'required_unless:result,'.CrossmatchResult::COMPATIBLE->value, 'string', 'max:2000'],
        ];
    }

    public function result(): CrossmatchResult
    {
        return CrossmatchResult::from($this->validated('result'));
    }
}
