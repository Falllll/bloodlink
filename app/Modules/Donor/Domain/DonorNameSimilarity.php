<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

/**
 * Satu-satunya ambang kemiripan nama donor (pg_trgm similarity). Dipakai
 * deduplikasi (FindSimilarDonors) dan pencarian daftar donor -- dua ambang
 * berbeda untuk hal yang sama berarti dua sumber kebenaran.
 */
final class DonorNameSimilarity
{
    public const float THRESHOLD = 0.4;
}
