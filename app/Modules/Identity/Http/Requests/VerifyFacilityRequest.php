<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\StrictRequest;

/** verified_by dan verified_at SENGAJA tidak ada: keduanya diisi server. */
final class VerifyFacilityRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
