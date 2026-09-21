<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\StrictRequest;

final class RegisterRequest extends StrictRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ];
    }
}
