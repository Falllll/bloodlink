<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum EligibilityFindingCode: string
{
    case AGE_BELOW_MINIMUM = 'AGE_BELOW_MINIMUM';
    case AGE_ABOVE_MAXIMUM = 'AGE_ABOVE_MAXIMUM';
    case DONATION_INTERVAL_NOT_MET = 'DONATION_INTERVAL_NOT_MET';
    case BODY_WEIGHT_BELOW_MINIMUM = 'BODY_WEIGHT_BELOW_MINIMUM';
    case ACTIVE_DEFERRAL = 'ACTIVE_DEFERRAL';
    case DATA_MISSING = 'DATA_MISSING';
}
