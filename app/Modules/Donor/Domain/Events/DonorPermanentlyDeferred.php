<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain\Events;

final class DonorPermanentlyDeferred
{
    public function __construct(
        public readonly int $donorId,
        public readonly string $reasonCode,
        public readonly string $occurredAt,
    ) {}
}
