<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Satu kandidat donor untuk satu permintaan pada satu radius (kartu 410).
 *
 * SENGAJA TIDAK memuat nama, telepon, email, alamat, atau golongan darah donor:
 * pengiriman pesan dilakukan server lewat modul Notification, dan klien tidak
 * pernah perlu memegang kontaknya. Penambahan apa pun ke daftar itu harus disengaja.
 */
final readonly class DonorCandidate
{
    public function __construct(
        public string $donorPublicId,
        public int $distanceMeters,
        public bool $isEligible,
        public ?string $ineligibleReasonCode,
        public bool $isReachable,
    ) {}

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'donor_id' => $this->donorPublicId,
            'distance_meters' => $this->distanceMeters,
            'is_eligible' => $this->isEligible,
            'ineligible_reason_code' => $this->ineligibleReasonCode,
            'is_reachable' => $this->isReachable,
        ];
    }
}
