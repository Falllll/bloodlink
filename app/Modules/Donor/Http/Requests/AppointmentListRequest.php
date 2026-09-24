<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\ListRequest;

final class AppointmentListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['status', 'donor_id'];

    /** @var array<int, string> */
    protected array $sortable = ['scheduled_for', 'created_at'];

    public function authorize(): bool
    {
        return true;
    }
}
