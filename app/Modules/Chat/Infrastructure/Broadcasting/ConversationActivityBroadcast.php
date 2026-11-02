<?php

declare(strict_types=1);

namespace App\Modules\Chat\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Aktivitas percakapan di channel fasilitas milik kartu 490 (kartu 495). TIDAK PERNAH
 * membawa isi pesan: body adalah data kesehatan, dan isi frame WebSocket berakhir di
 * log proxy dan memori browser. Klien membaca isinya lewat API yang dijaga policy.
 *
 * ShouldDispatchAfterCommit wajib: action Chat menulis di dalam transaksi dan
 * config/queue.php menyetel after_commit => false.
 */
final class ConversationActivityBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    /** @param array<string, string> $payload */
    public function __construct(
        private readonly string $facilityId,
        private readonly string $name,
        private readonly array $payload,
    ) {}

    public static function messagePosted(string $facilityId, string $conversationId, string $messageId, string $senderKind): self
    {
        return new self($facilityId, 'conversation.message-posted', [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'sender_kind' => $senderKind,
        ]);
    }

    public static function escalated(string $facilityId, string $conversationId, string $reason): self
    {
        return new self($facilityId, 'conversation.escalated', [
            'conversation_id' => $conversationId,
            'escalation_reason' => $reason,
        ]);
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('facility.'.$this->facilityId);
    }

    public function broadcastAs(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
