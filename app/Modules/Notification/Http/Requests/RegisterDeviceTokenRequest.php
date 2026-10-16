<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use App\Modules\Notification\Domain\DevicePlatform;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class RegisterDeviceTokenRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
        ];
    }

    public function platform(): DevicePlatform
    {
        return DevicePlatform::from($this->validated('platform'));
    }
}
