<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use DateTimeImmutable;

final readonly class EligibilityDecision
{
    /** @param list<EligibilityFinding> $findings */
    public function __construct(
        public EligibilityOutcome $outcome,
        public array $findings,
        public ?DateTimeImmutable $retryAfterDate,
    ) {}
}
