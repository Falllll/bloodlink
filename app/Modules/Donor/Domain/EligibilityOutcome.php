<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum EligibilityOutcome: string
{
    case ELIGIBLE = 'eligible';
    case NOT_ELIGIBLE = 'not_eligible';
    case UNDETERMINED = 'undetermined';
}
