<?php

declare(strict_types=1);

namespace App\Modules\Chat\Domain;

/**
 * Graf status percakapan (kartu 495). Nilai harus sama dengan enum kolom
 * conversations.status. Satu arah: percakapan tidak pernah kembali ke OPEN, dan
 * CLOSED terminal.
 */
enum ConversationStatus: string
{
    case OPEN = 'open';
    case ESCALATED = 'escalated';
    case ASSIGNED = 'assigned';
    case CLOSED = 'closed';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::OPEN => [self::ESCALATED, self::CLOSED],
            self::ESCALATED => [self::ASSIGNED, self::CLOSED],
            self::ASSIGNED => [self::CLOSED],
            self::CLOSED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), strict: true);
    }
}
