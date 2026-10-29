<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use App\Shared\Http\ListRequest;

final class NotificationHistoryRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $sortable = ['dispatched_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** Riwayat: terbaru dulu bila klien tidak meminta urutan lain. */
    public function sort(): string
    {
        return parent::sort() ?? '-dispatched_at';
    }
}
