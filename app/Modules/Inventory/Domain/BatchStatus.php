<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum BatchStatus: string
{
    case QUARANTINED = 'quarantined';
    case TESTING = 'testing';
    case RELEASED = 'released';
    case RESERVED = 'reserved';
    case ISSUED = 'issued';
    case DISCARDED = 'discarded';
    case EXPIRED = 'expired';
}
