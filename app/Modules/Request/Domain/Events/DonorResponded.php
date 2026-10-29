<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain\Events;

/**
 * Satu donor menjawab undangan kampanye (kartu 500). SENGAJA tanpa identitas
 * donor: petugas melihat agregat saja, dan payload antrean maupun frame
 * WebSocket tidak boleh membawa data pribadi.
 *
 * Jawaban sebagai string, bukan CampaignResponse: layer EvtRequest tidak boleh
 * bergantung pada DomRequest (deptrac).
 */
final class DonorResponded
{
    public function __construct(
        public readonly string $campaignId,
        public readonly string $bloodRequestId,
        public readonly string $facilityId,
        public readonly string $response,
    ) {}
}
