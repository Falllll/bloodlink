<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\ListRequest;

final class StaffListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['is_active'];

    /** @var array<int, string> */
    protected array $sortable = ['name', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }
}
