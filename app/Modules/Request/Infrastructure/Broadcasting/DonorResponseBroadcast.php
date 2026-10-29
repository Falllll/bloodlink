<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Jawaban donor atas undangan kampanye (kartu 500), di channel fasilitas milik
 * kartu 490. ShouldDispatchAfterCommit wajib: respond() menulis di dalam transaksi
 * dan config/queue.php menyetel after_commit => false.
 *
 * Bukan notifikasi: ia hanya memberi tahu layar yang terbuka bahwa agregatnya
 * berubah. Klien membaca ulang GET /dashboard/campaigns.
 */
final class DonorResponseBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(
        private readonly string $campaignId,
        private readonly string $bloodRequestId,
        private readonly string $facilityId,
        private readonly string $response,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('facility.'.$this->facilityId);
    }

    public function broadcastAs(): string
    {
        return 'campaign.donor-responded';
    }

    /**
     * Tanpa identitas, jarak, maupun tahap radius siapa pun: begitu masuk frame
     * WebSocket, data itu keluar dari jangkauan ScopedToFacility.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'blood_request_id' => $this->bloodRequestId,
            'response' => $this->response,
        ];
    }
}
