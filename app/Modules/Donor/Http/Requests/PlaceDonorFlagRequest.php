<?php

declare(strict_types=1);

namespace App\Modules\Donor\Http\Requests;

use App\Modules\Donor\Domain\DonorFlagReason;
use App\Shared\Http\StrictRequest;
use DateTimeImmutable;
use Illuminate\Validation\Rule;

/**
 * donor_id SENGAJA tidak ada di rules(): StrictRequest membalasnya 422. Donor
 * selalu dari path, tidak pernah dari body.
 */
final class PlaceDonorFlagRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(DonorFlagReason::class)],
            // Wajib: sanksi tanpa alasan tertulis tidak bisa dipertanggungjawabkan.
            'note' => ['required', 'string', 'max:2000'],
            // Kosong = tanpa batas waktu (tetap bisa dicabut).
            'ends_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function reason(): DonorFlagReason
    {
        return DonorFlagReason::from($this->validated('reason'));
    }

    public function endsAt(): ?DateTimeImmutable
    {
        $value = $this->validated('ends_at');

        return $value === null ? null : new DateTimeImmutable($value);
    }
}
