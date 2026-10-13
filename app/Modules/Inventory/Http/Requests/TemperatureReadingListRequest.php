<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Http\ListRequest;

final class TemperatureReadingListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['is_out_of_range', 'source'];

    /** @var array<int, string> */
    protected array $sortable = ['recorded_at'];

    public function authorize(): bool
    {
        return true;
    }
}
