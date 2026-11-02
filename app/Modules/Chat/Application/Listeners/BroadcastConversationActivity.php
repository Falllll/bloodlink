<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application\Listeners;

use App\Modules\Chat\Domain\Events\ConversationEscalated;
use App\Modules\Chat\Domain\Events\MessagePosted;
use App\Modules\Chat\Infrastructure\Broadcasting\ConversationActivityBroadcast;

// Menerjemahkan event domain Chat ke siaran (kartu 495). Penundaan sampai commit
// milik ConversationActivityBroadcast (ShouldDispatchAfterCommit), bukan listener ini.
final class BroadcastConversationActivity
{
    public function handleMessagePosted(MessagePosted $event): void
    {
        // Percakapan tanpa fasilitas tidak punya channel -- dan tidak punya petugas.
        if ($event->facilityId === null) {
            return;
        }

        broadcast(ConversationActivityBroadcast::messagePosted($event->facilityId, $event->conversationId, $event->messageId, $event->senderKind));
    }

    public function handleEscalated(ConversationEscalated $event): void
    {
        broadcast(ConversationActivityBroadcast::escalated($event->facilityId, $event->conversationId, $event->reason));
    }
}
