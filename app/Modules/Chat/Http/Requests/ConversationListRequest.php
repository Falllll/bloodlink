<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Requests;

use App\Shared\Http\ListRequest;

/** Kotak masuk petugas (kartu 495). */
final class ConversationListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['status'];

    /** @var array<int, string> */
    protected array $sortable = ['escalated_at'];

    public function authorize(): bool
    {
        return true;
    }
}
