<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

enum ReadingSource: string
{
    case MANUAL = 'manual';
    case DEVICE = 'device';
}
