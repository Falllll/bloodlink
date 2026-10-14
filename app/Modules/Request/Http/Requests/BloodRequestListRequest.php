<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Shared\Http\ListRequest;

final class BloodRequestListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['status', 'urgency', 'blood_group', 'component'];

    /** @var array<int, string> */
    protected array $sortable = ['needed_by', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }
}
