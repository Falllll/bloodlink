<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum TransferStatus: string
{
    case REQUESTED = 'requested';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SHIPPED = 'shipped';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::REQUESTED => [self::APPROVED, self::REJECTED, self::CANCELLED],
            self::APPROVED => [self::SHIPPED, self::CANCELLED],
            // Sudah di jalan: tidak bisa dibatalkan, hanya diterima.
            self::SHIPPED => [self::RECEIVED],
            self::REJECTED, self::RECEIVED, self::CANCELLED => [],
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

    /**
     * Transfer yang masih menahan unitnya dari alokasi. Harus sama dengan
     * predikat partial index stock_transfers_one_open_per_unit.
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::REQUESTED, self::APPROVED, self::SHIPPED => true,
            self::REJECTED, self::RECEIVED, self::CANCELLED => false,
        };
    }
}
