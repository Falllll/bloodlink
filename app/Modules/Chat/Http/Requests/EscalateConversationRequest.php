<?php

declare(strict_types=1);

namespace App\Modules\Chat\Http\Requests;

use App\Modules\Chat\Domain\EscalationReason;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * Pengunjung meminta dihubungkan ke petugas (kartu 495). facility_id WAJIB: eskalasi
 * tanpa fasilitas tidak terlihat oleh petugas mana pun.
 */
final class EscalateConversationRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::enum(EscalationReason::class)],
            'facility_id' => ['required', 'uuid', Rule::exists('facilities', 'public_id')->where('is_active', true)],
        ];
    }

    public function reason(): EscalationReason
    {
        return EscalationReason::from((string) $this->validated('reason'));
    }

    public function facilityPublicId(): string
    {
        return (string) $this->validated('facility_id');
    }
}
