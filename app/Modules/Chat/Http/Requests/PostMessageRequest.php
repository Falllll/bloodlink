<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Requests;

use App\Shared\Http\StrictRequest;

/** Satu pesan, dari pengunjung atau petugas (kartu 495). Pengirim dari rute, tidak pernah dari body. */
final class PostMessageRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }

    public function body(): string
    {
        return (string) $this->validated('body');
    }
}
