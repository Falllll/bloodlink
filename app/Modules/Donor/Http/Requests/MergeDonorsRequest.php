<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;

final class MergeDonorsRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true; // authorization lives in Gate::authorize() in the controller
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_id' => ['required', 'uuid', 'exists:donors,public_id', 'different:target_id'],
            'target_id' => ['required', 'uuid', 'exists:donors,public_id'],
        ];
    }
}
