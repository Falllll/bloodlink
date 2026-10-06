<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class TransitionBloodBatchRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(BatchStatus::class)],
            'discard_reason' => ['required_if:status,discarded', 'prohibited_unless:status,discarded', 'nullable', 'string', 'max:255'],
        ];
    }

    public function status(): BatchStatus
    {
        return BatchStatus::from($this->validated('status'));
    }

    public function discardReason(): ?string
    {
        return $this->validated('discard_reason');
    }
}
