<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum BatchStatus: string
{
    case QUARANTINED = 'quarantined';
    case TESTING = 'testing';
    case RELEASED = 'released';
    case RESERVED = 'reserved';
    case ISSUED = 'issued';
    case DISCARDED = 'discarded';
    case EXPIRED = 'expired';
    // Induk whole blood yang sudah dipisah jadi komponen (Kartu 220). Bukan
    // DISCARDED: pemisahan bukan kehilangan stok, dan bukan jalur pemusnahan.
    case SEPARATED = 'separated';

    /**
     * Graf lengkap siklus hidup unit. TESTING -> RELEASED ada di sini karena
     * memang sah secara domain, tapi hanya gerbang rilis (Kartu 240) yang boleh
     * menempuhnya -- TransitionBloodBatch menolaknya lebih dulu. Hal yang sama
     * untuk SEPARATED: hanya SeparateIntoComponents yang boleh mencapainya.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::QUARANTINED => [self::TESTING, self::SEPARATED, self::DISCARDED, self::EXPIRED],
            self::TESTING => [self::RELEASED, self::SEPARATED, self::DISCARDED, self::EXPIRED],
            self::RELEASED => [self::RESERVED, self::ISSUED, self::DISCARDED, self::EXPIRED],
            self::RESERVED => [self::RELEASED, self::ISSUED, self::DISCARDED, self::EXPIRED],
            self::ISSUED, self::DISCARDED, self::EXPIRED, self::SEPARATED => [],
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
