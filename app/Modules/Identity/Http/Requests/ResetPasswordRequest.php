<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\StrictRequest;

final class ResetPasswordRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Aturan password sama dengan register. Tanpa email di body: token sudah
     * mengidentifikasi akunnya, dan email tambahan hanya membuka oracle kedua.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'min:8'],
        ];
    }
}
