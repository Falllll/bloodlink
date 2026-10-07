<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum TtiTestRequirement: string
{
    /** §6.1: selalu ada di panel setiap fasilitas; tidak bisa dimatikan. */
    case MANDATORY = 'mandatory';

    /** §6.2: hanya masuk panel fasilitas yang meng-opt-in-nya. */
    case REGIONAL = 'regional';
}
