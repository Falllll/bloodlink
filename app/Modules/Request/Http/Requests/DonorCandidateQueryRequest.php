<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class DonorCandidateQueryRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Hanya anak tangga yang dikenal: klien tidak bisa meminta radius sembarang.
            'radius_meters' => ['required', 'integer', Rule::in((array) config('matching.staged_radii_meters'))],
        ];
    }

    public function radiusMeters(): int
    {
        return (int) $this->validated('radius_meters');
    }
}
