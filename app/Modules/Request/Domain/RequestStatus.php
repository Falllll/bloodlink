<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Graf status permintaan darah. Kartu 310 HANYA menulis OPEN; status lain
 * belum ditulis kode mana pun -- alokasi (kartu 320) yang menaikkannya ke
 * partially_fulfilled/fulfilled. Bukan pekerjaan yang terlupa.
 */
enum RequestStatus: string
{
    case OPEN = 'open';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case FULFILLED = 'fulfilled';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::OPEN => [self::PARTIALLY_FULFILLED, self::FULFILLED, self::CANCELLED, self::EXPIRED],
            self::PARTIALLY_FULFILLED => [self::FULFILLED, self::CANCELLED, self::EXPIRED],
            self::FULFILLED, self::CANCELLED, self::EXPIRED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), strict: true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }
}
