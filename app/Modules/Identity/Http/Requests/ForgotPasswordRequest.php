<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\StrictRequest;

final class ForgotPasswordRequest extends StrictRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Format saja -- SENGAJA tanpa `exists:users,email`: aturan itu menjawab
     * "akun ini ada" lewat pesan validasinya (kartu 485).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
