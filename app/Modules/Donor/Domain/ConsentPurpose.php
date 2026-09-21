<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

enum ConsentPurpose: string
{
    case DONATION = 'donation';
    case HEALTH_SCREENING = 'health_screening';
    case CONTACT_FOR_URGENT_REQUEST = 'contact_for_urgent_request';
    case DATA_RETENTION = 'data_retention';
}
