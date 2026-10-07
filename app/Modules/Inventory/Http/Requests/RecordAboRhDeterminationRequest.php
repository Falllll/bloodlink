<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Http\StrictRequest;
use DateTimeImmutable;
use Illuminate\Validation\Rule;

final class RecordAboRhDeterminationRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'blood_group' => ['required', Rule::in(['A', 'B', 'AB', 'O'])],
            'rh_factor' => ['required', Rule::in(['positive', 'negative'])],
            'determined_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }

    public function determinedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->validated('determined_at'));
    }
}
