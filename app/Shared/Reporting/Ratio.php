<?php

declare(strict_types=1);

namespace App\Shared\Reporting;

/**
 * Pembilang dan penyebut selalu dikirim bersama rasionya: 1,0 dari 2/2 dan 1,0
 * dari 400/400 adalah dua dunia berbeda.
 *
 * TIDAK ADA ambang mutu di sini, dan tidak boleh ada. Angka "baik" vs "buruk"
 * untuk wastage rate atau rasio C:T adalah aturan medis; ia wajib masuk halaman
 * Rujukan Pedoman Medis dengan pasal rujukannya sebelum ditampilkan sebagai
 * lampu hijau/merah di mana pun.
 */
final class Ratio
{
    /** @return array{numerator: int, denominator: int, ratio: float|null} */
    public static function of(int $numerator, int $denominator): array
    {
        return [
            'numerator' => $numerator,
            'denominator' => $denominator,
            // Penyebut 0 berarti "tidak terdefinisi", bukan "nol": 0 akan terbaca
            // sebagai kinerja sempurna pada periode tanpa aktivitas sama sekali.
            'ratio' => $denominator === 0 ? null : round($numerator / $denominator, 4),
        ];
    }
}
