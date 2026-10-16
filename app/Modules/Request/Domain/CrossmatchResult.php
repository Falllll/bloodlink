<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Hasil uji silang serologis antara sampel pasien dan satu kantong tertentu.
 * INCONCLUSIVE bukan INCOMPATIBLE: yang pertama menuntut pemeriksaan ulang,
 * yang kedua menuntut unit ditarik dari permintaan ini. Ketiganya kategori
 * keputusan operasional, bukan ambang medis.
 */
enum CrossmatchResult: string
{
    case COMPATIBLE = 'compatible';
    case INCOMPATIBLE = 'incompatible';
    case INCONCLUSIVE = 'inconclusive';

    public function permitsIssue(): bool
    {
        return $this === self::COMPATIBLE;
    }
}
