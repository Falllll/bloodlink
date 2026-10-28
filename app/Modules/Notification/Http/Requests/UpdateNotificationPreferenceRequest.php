<?php

declare(strict_types=1);

namespace App\Modules\Notification\Http\Requests;

use App\Modules\Notification\Domain\NotificationCategory;
use App\Shared\Http\StrictRequest;

final class UpdateNotificationPreferenceRequest extends StrictRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            // Menolak panggilan darurat = keluar dari daftar panggilan (kartu 488). Itu
            // harus disetujui EKSPLISIT, bukan efek samping satu toggle: tanpa
            // persetujuan, 422 dengan rule `accepted` -- tanda bagi klien untuk
            // menampilkan konfirmasinya.
            'acknowledge_leaving_call_list' => $this->leavesCallList() ? ['accepted'] : ['sometimes', 'boolean'],
        ];
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::from((string) $this->route('category'));
    }

    public function enabled(): bool
    {
        return (bool) $this->validated('enabled');
    }

    private function leavesCallList(): bool
    {
        return $this->route('category') === NotificationCategory::EMERGENCY_CALL->value
            && $this->has('enabled')
            && ! $this->boolean('enabled');
    }
}
