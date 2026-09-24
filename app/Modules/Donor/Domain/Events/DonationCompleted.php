<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Events;

final class DonationCompleted
{
    public function __construct(
        public readonly int $donorId,
        public readonly int $donationId,
    ) {}
}
