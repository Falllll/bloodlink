<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum DeferralSource: string
{
    case MANUAL = 'manual';
    case SCREENING = 'screening';
    case TTI_RESULT = 'tti_result';
}
