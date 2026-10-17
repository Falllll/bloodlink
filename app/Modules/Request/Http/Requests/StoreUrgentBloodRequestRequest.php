<?php

declare(strict_types=1);

namespace App\Modules\Request\Http\Requests;

use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * Sama persis dengan StoreBloodRequestRequest kecuali urgency: permintaan routine
 * tidak lewat pintu darurat. Aturannya DIAMBIL dari kelas itu (final, jadi lewat
 * komposisi, bukan pewarisan) supaya kedua pintu tidak bisa berbeda diam-diam.
 */
final class StoreUrgentBloodRequestRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...(new StoreBloodRequestRequest)->rules(),
            'urgency' => ['required', Rule::in([RequestUrgency::URGENT->value, RequestUrgency::EMERGENCY->value])],
        ];
    }
}
