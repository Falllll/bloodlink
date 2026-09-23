<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use DateTimeImmutable;

final readonly class DonorEligibilitySnapshot
{
    /** @param list<DeferralWindow> $deferrals */
    public function __construct(
        public DateTimeImmutable $dateOfBirth,
        public string $sex,                      // 'male' | 'female' — enum kolom donors.sex
        public ?DateTimeImmutable $lastDonationDate,
        public ?float $weightKg,
        public array $deferrals = [],
        public int $plannedVolumeMl = 450,
    ) {}

    public function ageOn(DateTimeImmutable $today): int
    {
        return (int) $this->dateOfBirth->diff($today)->y;
    }
}
