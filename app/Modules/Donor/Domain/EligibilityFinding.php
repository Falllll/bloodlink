<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use DateTimeImmutable;

final readonly class EligibilityFinding
{
    public function __construct(
        public EligibilityOutcome $outcome,
        public EligibilityFindingCode $code,
        public ?DateTimeImmutable $retryAfterDate = null,
        public ?string $detail = null,
    ) {}
}
