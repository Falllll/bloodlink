<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\StrictRequest;

final class LoginRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {

        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
