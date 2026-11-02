<?php

declare(strict_types=1);

namespace App\Modules\Chat\Domain;

/**
 * Siapa yang menulis satu pesan (kartu 495). Nilai harus sama dengan enum kolom
 * messages.sender_kind. Enum, bukan boolean is_bot: macam keempat (mis. pesan
 * sistem) berarti satu case baru, bukan kolom kedua yang bisa bertentangan.
 */
enum MessageSenderKind: string
{
    case VISITOR = 'visitor';
    case AI = 'ai';
    case STAFF = 'staff';
}
