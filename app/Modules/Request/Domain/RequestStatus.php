<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Graf status permintaan darah. Kartu 310 menulis OPEN; alokasi FEFO (kartu 320)
 * menaikkannya ke partially_fulfilled/fulfilled dan MENURUNKANNYA kembali saat
 * alokasi dilepas -- terpenuhi di sini berarti "semua kantong dialokasikan",
 * belum diterbitkan, jadi masih bisa mundur. CANCELLED dan EXPIRED ditulis
 * CancelBloodRequest (saga pembatalan, kartu 360) dan terminal.
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
            self::PARTIALLY_FULFILLED => [self::OPEN, self::FULFILLED, self::CANCELLED, self::EXPIRED],
            // Alokasi dilepas sebelum kantong diterbitkan (kartu 320). Terpenuhi masih
            // bisa dibatalkan/kedaluwarsa: justru di status inilah unit tertahan (kartu 360).
            self::FULFILLED => [self::OPEN, self::PARTIALLY_FULFILLED, self::CANCELLED, self::EXPIRED],
            self::CANCELLED, self::EXPIRED => [],
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
