<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application;

use App\Models\Conversation;
use App\Models\Facility;
use App\Models\Message;
use App\Modules\Chat\Application\Exceptions\ConversationRejected;
use App\Modules\Chat\Domain\ConversationStatus;
use App\Modules\Chat\Domain\Events\MessagePosted;
use App\Modules\Chat\Domain\MessageSenderKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menambah satu pesan (kartu 495). Riwayat hanya bertambah: tidak ada jalur yang
 * mengubah atau menghapus pesan, termasuk saat petugas mengambil alih.
 */
final class PostMessage
{
    public function handle(Conversation $conversation, MessageSenderKind $kind, string $body, ?int $senderUserId): Message
    {
        return DB::transaction(function () use ($conversation, $kind, $body, $senderUserId): Message {
            // Kunci baris percakapan: pesan tidak boleh menyelinap masuk di antara
            // pemeriksaan status dan penutupan oleh petugas.
            $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ConversationStatus::CLOSED) {
                throw ConversationRejected::closed();
            }

            $message = new Message(['sender_kind' => $kind, 'body' => $body]);
            $message->forceFill([
                'public_id' => (string) Str::uuid(),
                'conversation_id' => $locked->id,
                // messages_sender_shape menolak akun pengirim pada pesan non-petugas.
                'sender_user_id' => $kind === MessageSenderKind::STAFF ? $senderUserId : null,
            ])->save();

            $facilityPublicId = $locked->facility_id === null
                ? null
                : Facility::query()->whereKey($locked->facility_id)->value('public_id');

            event(new MessagePosted($locked->public_id, $message->public_id, $kind->value, $facilityPublicId));

            return $message;
        });
    }
}
