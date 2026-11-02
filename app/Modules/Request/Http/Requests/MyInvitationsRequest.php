<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\ListRequest;

final class MyInvitationsRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $sortable = ['contacted_at'];

    public function authorize(): bool
    {
        return true;
    }

    /** Undangan: terbaru dulu bila klien tidak meminta urutan lain. */
    public function sort(): string
    {
        return parent::sort() ?? '-contacted_at';
    }
}
