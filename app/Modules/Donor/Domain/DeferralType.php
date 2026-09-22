<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum DeferralType: string
{
    case TEMPORARY = 'temporary';
    case PERMANENT = 'permanent';
}
