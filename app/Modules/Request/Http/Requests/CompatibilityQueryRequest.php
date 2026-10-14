<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class CompatibilityQueryRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'component' => ['required', Rule::enum(BloodComponent::class)],
            'recipient_group' => ['required', Rule::enum(BloodGroup::class)],
            'recipient_rh' => ['required', Rule::enum(RhFactor::class)],
        ];
    }
}
