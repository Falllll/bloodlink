<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Http\ListRequest;

final class BloodBatchListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['status', 'component', 'blood_group', 'rh_factor'];

    /** @var array<int, string> */
    protected array $sortable = ['expires_at', 'collected_at', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }
}
