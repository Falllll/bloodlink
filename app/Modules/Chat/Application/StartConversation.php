<?php

declare(strict_types=1);

namespace App\Modules\Chat\Application;

use App\Models\Conversation;
use App\Modules\Chat\Domain\ConversationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membuka percakapan pengunjung (kartu 495). Token pengunjung dibuat pemanggil,
 * dikembalikan ke klien SEKALI, dan di sini hanya hash-nya yang disimpan.
 *
 * Sengaja BUKAN token Sanctum: token Sanctum di repo ini tidak pernah kedaluwarsa
 * (config/sanctum.php tanpa expiration), sedangkan identitas pengunjung anonim harus.
 */
final class StartConversation
{
    /** Umur token pengunjung. Habis = percakapan baru, bukan perpanjangan diam-diam. */
    public const int VISITOR_TTL_HOURS = 24;

    public function handle(?int $facilityId, string $visitorToken): Conversation
    {
        return DB::transaction(function () use ($facilityId, $visitorToken): Conversation {
            $conversation = new Conversation;
            $conversation->forceFill([
                'public_id' => (string) Str::uuid(),
                'facility_id' => $facilityId,
                'status' => ConversationStatus::OPEN,
                'visitor_token_hash' => Conversation::visitorTokenHash($visitorToken),
                'visitor_expires_at' => now()->addHours(self::VISITOR_TTL_HOURS),
            ])->save();

            return $conversation;
        });
    }
}
