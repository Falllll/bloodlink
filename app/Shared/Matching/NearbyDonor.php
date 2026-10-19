<?php

declare(strict_types=1);

namespace App\Shared\Matching;

/** Satu donor dalam radius: identitas internal, public_id, dan jarak. Tanpa data kontak. */
final readonly class NearbyDonor
{
    public function __construct(
        public int $donorId,
        public string $donorPublicId,
        public int $distanceMeters,
    ) {}
}
