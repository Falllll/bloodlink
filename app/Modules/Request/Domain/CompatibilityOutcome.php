<?php

declare(strict_types=1);

namespace App\Modules\Request\Domain;

/** Tiga nilai, bukan boolean: komponen tanpa aturan bersumber tidak boleh jatuh ke salah satu sisi. */
enum CompatibilityOutcome: string
{
    case COMPATIBLE = 'compatible';
    case INCOMPATIBLE = 'incompatible';
    /** Tidak ada aturan bersumber untuk komponen ini -- lihat Rujukan Pedoman Medis §12.6. */
    case UNSUPPORTED = 'unsupported';
}
