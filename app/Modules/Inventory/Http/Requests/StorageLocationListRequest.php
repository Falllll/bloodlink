<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Http\ListRequest;

final class StorageLocationListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['kind', 'purpose', 'is_active'];

    /** @var array<int, string> */
    protected array $sortable = ['code', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }
}
