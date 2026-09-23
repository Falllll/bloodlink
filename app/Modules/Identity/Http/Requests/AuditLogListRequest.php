<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Shared\Http\ListRequest;

final class AuditLogListRequest extends ListRequest
{
    /** @var array<int, string> */
    protected array $filterable = ['auditable_type', 'auditable_id', 'action', 'actor_id', 'actor_facility_id', 'trace_id'];

    /** @var array<int, string> */
    protected array $sortable = ['occurred_at', 'id'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'occurred_from' => ['sometimes', 'date'],
            'occurred_to' => ['sometimes', 'date', 'after_or_equal:occurred_from'],
        ]);
    }

    public function occurredFrom(): ?string
    {
        return $this->validated('occurred_from');
    }

    public function occurredTo(): ?string
    {
        return $this->validated('occurred_to');
    }
}
