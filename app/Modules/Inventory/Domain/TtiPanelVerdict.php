<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Bacaan atas hasil SKRINING satu panel. Hanya dibaca -- vonis ini tidak
 * pernah merilis unit; itu gerbang rilis (Kartu 240).
 *
 * Hasil konfirmasi (§6.3) sengaja tidak ikut: unit yang reaktif pada skrining
 * tetap reaktif, apa pun hasil konfirmasinya. Konfirmasi untuk penanganan
 * donor, bukan untuk "membersihkan" kantong.
 */
enum TtiPanelVerdict: string
{
    /** Masih ada uji panel yang belum punya hasil skrining. */
    case INCOMPLETE = 'incomplete';

    /** Setiap uji panel punya hasil skrining, dan semuanya non-reaktif. */
    case ALL_NON_REACTIVE = 'all_non_reactive';

    case REACTIVE = 'reactive';

    case INDETERMINATE = 'indeterminate';

    /**
     * @param  list<TtiTestCode>  $panel
     * @param  array<string, TtiResult>  $screeningResults  dikunci TtiTestCode->value
     */
    public static function decide(array $panel, array $screeningResults): self
    {
        // Reaktif menang atas apa pun, termasuk uji di luar panel yang
        // kebetulan tercatat: hasil reaktif tidak pernah diabaikan.
        if (in_array(TtiResult::REACTIVE, $screeningResults, true)) {
            return self::REACTIVE;
        }

        if (in_array(TtiResult::INDETERMINATE, $screeningResults, true)) {
            return self::INDETERMINATE;
        }

        foreach ($panel as $code) {
            if (! array_key_exists($code->value, $screeningResults)) {
                return self::INCOMPLETE;
            }
        }

        return self::ALL_NON_REACTIVE;
    }
}
