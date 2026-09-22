<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum DeferralDurationUnit: string
{
    case HOURS = 'hours';
    case DAYS = 'days';
    case MONTHS = 'months';
    case YEARS = 'years';
}
