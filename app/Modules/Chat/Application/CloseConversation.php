<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application;

use App\Models\Conversation;
use App\Modules\Chat\Application\Exceptions\ConversationRejected;
use App\Modules\Chat\Domain\ConversationStatus;
use Illuminate\Support\Facades\DB;

/** Menutup percakapan (kartu 495). Sekali jalan: menutup dua kali ditolak 409. */
final class CloseConversation
{
    public function handle(Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($conversation): Conversation {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo(ConversationStatus::CLOSED)) {
                throw ConversationRejected::closed();
            }

            $locked->forceFill(['status' => ConversationStatus::CLOSED, 'closed_at' => now()])->save();

            return $locked;
        });
    }
}
