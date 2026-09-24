<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum AppointmentStatus: string
{
    case BOOKED = 'booked';
    case ARRIVED = 'arrived';
    case SCREENED = 'screened';
    case COMPLETED = 'completed';
    case NO_SHOW = 'no_show';
    case CANCELLED = 'cancelled';

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::BOOKED => [self::ARRIVED, self::NO_SHOW, self::CANCELLED],
            self::ARRIVED => [self::SCREENED, self::CANCELLED],
            self::SCREENED => [self::COMPLETED, self::CANCELLED],
            self::COMPLETED, self::NO_SHOW, self::CANCELLED => [],
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
