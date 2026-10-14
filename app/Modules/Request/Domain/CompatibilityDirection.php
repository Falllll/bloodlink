<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/**
 * Arah pemeriksaan ABO. Plasma memeriksa KEBALIKAN sel darah merah: antibodi
 * donor terhadap sel penerima (WHO Module 3 §6.3 Figure 15; Rujukan §12.4).
 */
enum CompatibilityDirection: string
{
    case RED_CELLS = 'red_cells';
    case PLASMA = 'plasma';
}
