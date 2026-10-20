<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use DateTimeImmutable;

/**
 * Satu flag administratif sebagai value object murni (pola DeferralWindow): domain
 * tidak mengenal Eloquent. Hanya flag yang BELUM dicabut yang dimuat ke snapshot;
 * pencabutan disaring di query (FlagDonor::whereActive).
 */
final readonly class DonorFlagWindow
{
    public function __construct(
        public DonorFlagReason $reason,
        public ?DateTimeImmutable $endsAt = null,
    ) {}

    /**
     * Klausa yang sama dengan FlagDonor::whereActive (ends_at null ATAU ends_at > hari
     * ini) -- dua definisi "aktif" yang berbeda menghasilkan jawaban bertentangan.
     */
    public function isActiveOn(DateTimeImmutable $today): bool
    {
        return $this->endsAt === null || $this->endsAt > $today;
    }
}
