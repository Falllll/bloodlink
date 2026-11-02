<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application;

use App\Models\Conversation;
use App\Models\Facility;
use App\Modules\Chat\Application\Exceptions\ConversationRejected;
use App\Modules\Chat\Domain\ConversationStatus;
use App\Modules\Chat\Domain\EscalationReason;
use App\Modules\Chat\Domain\Events\ConversationEscalated;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan percakapan ke petugas satu fasilitas (kartu 495). Status, waktu,
 * alasan, DAN fasilitas ditulis bersama: eskalasi tanpa fasilitas membuat
 * percakapan hilang dari setiap kotak masuk -- CHECK conversations_escalation_shape
 * menolaknya di database kalau jalur baru lupa.
 */
final class EscalateConversation
{
    public function handle(Conversation $conversation, EscalationReason $reason, int $facilityId): Conversation
    {
        return DB::transaction(function () use ($conversation, $reason, $facilityId): Conversation {
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ConversationStatus::CLOSED) {
                throw ConversationRejected::closed();
            }

            if (! $locked->status->canTransitionTo(ConversationStatus::ESCALATED)) {
                throw ConversationRejected::alreadyEscalated();
            }

            $locked->forceFill([
                'status' => ConversationStatus::ESCALATED,
                'escalated_at' => now(),
                'escalation_reason' => $reason,
                'facility_id' => $facilityId,
            ])->save();

            event(new ConversationEscalated(
                $locked->public_id,
                (string) Facility::query()->whereKey($facilityId)->value('public_id'),
                $reason->value,
            ));

            return $locked;
        });
    }
}
