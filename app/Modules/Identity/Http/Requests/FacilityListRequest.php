<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\ListRequest;

final class FacilityListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['type', 'city', 'province', 'is_active'];

    /** @var array<int, string> */
    protected array $sortable = ['name', 'code', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }
}
