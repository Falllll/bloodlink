<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use DateInterval;
use DateTimeImmutable;

final readonly class ShelfLifeWindow
{
    public function __construct(
        public DateTimeImmutable $collectedAt,
        public int $value,
        public ShelfLifeUnit $unit,
    ) {}

    /**
     * Bulan adalah bulan kalender (P3M, bukan P90D) -- preseden DeferralDurationUnit.
     */
    public function expiresAt(): DateTimeImmutable
    {
        $expiry = $this->collectedAt->add(new DateInterval(
            $this->unit->toDateIntervalSpec($this->value)
        ));

        // P3M dari 31 Jan meluap ke 1 Mei. Untuk kedaluwarsa, luapan berarti unit
        // hidup lebih lama dari seharusnya — tarik ke hari terakhir bulan tujuan.
        if ($this->unit === ShelfLifeUnit::MONTHS && $expiry->format('j') !== $this->collectedAt->format('j')) {
            $expiry = $expiry->modify('last day of previous month');
        }

        return $expiry;
    }
}
