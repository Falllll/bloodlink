<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Requests;

use App\Shared\Http\ListRequest;

/** Riwayat pesan satu percakapan, urut tulis (id naik). Tanpa filter. */
final class MessageListRequest extends ListRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
