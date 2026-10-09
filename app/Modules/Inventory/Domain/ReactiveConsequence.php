<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Konsekuensi SATU baris hasil IMLTD. Rujukan Pedoman Medis §11.3: skrining
 * reaktif menjatuhkan unit, konfirmasi reaktif menjatuhkan donor.
 */
enum ReactiveConsequence: string
{
    case NONE = 'none';

    /** Skrining reaktif: unit + seluruh turunannya keluar dari stok (§11.1). */
    case DISCARD_UNIT = 'discard_unit';

    /** Konfirmasi reaktif: donor di-deferral permanen (§11.2). Unitnya sudah jatuh di langkah skrining. */
    case DEFER_DONOR_PERMANENTLY = 'defer_donor_permanently';

    public static function decide(TtiResult $result, bool $confirmatory): self
    {
        // INDETERMINATE sengaja NONE -- §11.3 butir 4 memberinya kategori sendiri.
        if ($result !== TtiResult::REACTIVE) {
            return self::NONE;
        }

        return $confirmatory ? self::DEFER_DONOR_PERMANENTLY : self::DISCARD_UNIT;
    }
}
