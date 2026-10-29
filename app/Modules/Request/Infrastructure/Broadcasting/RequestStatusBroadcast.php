<?php

declare(strict_types=1);

namespace App\Modules\Request\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Satu-satunya kelas di repo ini yang menyentuh transport WebSocket (kartu 490).
 * ShouldDispatchAfterCommit wajib: status ditulis di dalam transaksi dan
 * config/queue.php menyetel after_commit => false -- tanpanya layar bisa
 * menunjukkan status dari transaksi yang kemudian di-rollback.
 *
 * Broadcast BUKAN notifikasi: ia hanya menyegarkan layar yang sedang terbuka.
 * Pemberitahuan ke orang tetap lewat DispatchNotification.
 */
final class RequestStatusBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(
        private readonly string $requestId,
        private readonly string $facilityId,
        private readonly string $status,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('facility.'.$this->facilityId);
    }

    public function broadcastAs(): string
    {
        return 'blood-request.status';
    }

    /**
     * Hanya id dan status: isi frame WebSocket berakhir di log proxy dan memori
     * browser. Tidak ada referensi pasien, golongan darah, maupun catatan --
     * klien yang butuh detail memintanya lewat API yang dijaga ScopedToFacility.
     *
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'status' => $this->status,
        ];
    }
}
