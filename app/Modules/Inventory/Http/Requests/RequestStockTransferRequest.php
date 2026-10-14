<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * Fasilitas tujuan tidak ada di body: ia selalu fasilitas pemegang token.
 */
final class RequestStockTransferRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'uuid', Rule::exists('blood_batches', 'public_id')],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
