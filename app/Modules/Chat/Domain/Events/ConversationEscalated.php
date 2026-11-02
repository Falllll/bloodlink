<?php

declare(strict_types=1);

namespace App\Modules\Chat\Domain\Events;

/**
 * Percakapan dipindahkan ke petugas sebuah fasilitas (kartu 495). Hanya id dan
 * alasan terstruktur -- tidak ada isi percakapan.
 */
final class ConversationEscalated
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $facilityId,
        public readonly string $reason,
    ) {}
}
