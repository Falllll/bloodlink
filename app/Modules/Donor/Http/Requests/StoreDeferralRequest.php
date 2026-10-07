<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Shared\Http\StrictRequest;
use DateTimeImmutable;
use Illuminate\Validation\Rule;

final class StoreDeferralRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Cukup "kodenya ada". Kode yang dinonaktifkan ditolak PlaceDeferral
            // sebagai 409 DONOR_DEFERRAL_CONFLICT -- aturan itu milik domain.
            'reason_code' => ['required', 'string', Rule::exists('deferral_reasons', 'code')->where('jurisdiction', 'WHO')],
            'anchor_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function anchorAt(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->validated('anchor_at'));
    }
}
