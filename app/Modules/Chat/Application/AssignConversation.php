<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application;

use App\Models\Conversation;
use App\Modules\Chat\Application\Exceptions\ConversationRejected;
use App\Modules\Chat\Domain\ConversationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Petugas mengambil alih percakapan yang sudah dieskalasi (kartu 495). Riwayat
 * pesan sebelumnya -- termasuk yang ditulis AI -- tidak disentuh.
 */
final class AssignConversation
{
    public function handle(Conversation $conversation, int $userId): Conversation
    {
        return DB::transaction(function () use ($conversation, $userId): Conversation {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(ConversationStatus::ASSIGNED)) {
                throw $locked->status === ConversationStatus::CLOSED
                    ? ConversationRejected::closed()
                    : ConversationRejected::cannotTransition($locked->status, ConversationStatus::ASSIGNED);
            }

            $locked->forceFill(['status' => ConversationStatus::ASSIGNED, 'assigned_user_id' => $userId])->save();

            return $locked;
        });
    }
}
