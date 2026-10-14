<?php

declare(strict_types=1);

namespace App\Shared\Blood;

/** Rhesus (D). Nilainya persis enum kolom rh_factor di PostgreSQL. */
enum RhFactor: string
{
    case POSITIVE = 'positive';
    case NEGATIVE = 'negative';
}
