<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Modules\Inventory\Domain\TransferStatus;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/** Dipakai approve maupun reject; langkah lain punya endpoint sendiri. */
final class DecideStockTransferRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([TransferStatus::APPROVED->value, TransferStatus::REJECTED->value])],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function status(): TransferStatus
    {
        return TransferStatus::from($this->validated('status'));
    }
}
