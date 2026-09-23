<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use DateTimeImmutable;

interface EligibilityRule
{
    public function evaluate(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): ?EligibilityFinding;
}
