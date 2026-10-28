<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\StrictRequest;

final class ChangePasswordRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Password lama WAJIB (kartu 485): token yang dicuri saja tidak boleh cukup untuk
     * mengunci pemilik akun keluar. Aturan password baru sama dengan register.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', 'confirmed', 'min:8', 'different:current_password'],
        ];
    }
}
