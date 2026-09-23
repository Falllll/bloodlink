<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use InvalidArgumentException;

enum DeferralDurationUnit: string
{
    case HOURS = 'hours';
    case DAYS = 'days';
    case MONTHS = 'months';
    case YEARS = 'years';

    public function toDateIntervalSpec(int $value): string
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Duration value must be a positive integer.');
        }

        return match ($this) {
            self::HOURS => "PT{$value}H",
            self::DAYS => "P{$value}D",
            self::MONTHS => "P{$value}M",
            self::YEARS => "P{$value}Y",
        };
    }
}
