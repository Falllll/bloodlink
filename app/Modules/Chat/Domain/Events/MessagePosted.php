<?php

declare(strict_types=1);

namespace App\Modules\Chat\Domain\Events;

/**
 * Satu pesan baru ditulis (kartu 495). SENGAJA tanpa isi pesan: body adalah data
 * kesehatan dan tidak boleh berakhir di payload antrean maupun frame WebSocket.
 *
 * Semua string: layer EvtChat hanya boleh melihat BloodKernel (deptrac), jadi enum
 * MessageSenderKind dioper sebagai nilainya. facilityId null = percakapan belum
 * punya fasilitas, dan tidak ada channel yang bisa menerimanya.
 */
final class MessagePosted
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $senderKind,
        public readonly ?string $facilityId,
    ) {}
}
