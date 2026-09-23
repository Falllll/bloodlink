<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

final class WhoEligibilityLimits
{
    // Rujukan Pedoman Medis §3.1 (Dikutip): batas bawah lazim 18,
    // batas bawah mutlak 16, batas atas lazim 65.
    // Tidak ada batas atas mutlak di sumber.
    public const int AGE_MIN_USUAL_YEARS = 18;

    public const int AGE_MIN_ABSOLUTE_YEARS = 16;

    public const int AGE_MAX_USUAL_YEARS = 65;

    // Rujukan §3.5 (Dikutip): 12 minggu laki-laki, 16 minggu perempuan.
    // Minggu -> hari AMAN (selalu 7). Bulan -> hari TIDAK aman.
    public const int INTERVAL_MALE_DAYS = 84;

    public const int INTERVAL_FEMALE_DAYS = 112;

    // Rujukan §3.2 (Dikutip): ambang bergantung volume, bukan tunggal.
    public const float WEIGHT_MIN_350ML_KG = 45.0;

    public const float WEIGHT_MIN_450ML_KG = 50.0;

    public const float WEIGHT_MIN_APHERESIS_KG = 50.0;
}
