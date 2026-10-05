<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use InvalidArgumentException;

enum ShelfLifeUnit: string
{
    case HOURS = 'hours';
    case DAYS = 'days';
    case MONTHS = 'months';

    public function toDateIntervalSpec(int $value): string
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Shelf life value must be a positive integer.');
        }

        return match ($this) {
            self::HOURS => "PT{$value}H",
            self::DAYS => "P{$value}D",
            self::MONTHS => "P{$value}M",
        };
    }
}
