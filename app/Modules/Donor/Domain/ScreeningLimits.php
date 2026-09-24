<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

final class ScreeningLimits
{
    public const int HAEMOGLOBIN_MIN_FEMALE_DG_DL = 120;   // §3.3, 12,0 g/dL

    public const int HAEMOGLOBIN_MIN_MALE_DG_DL = 130;   // §3.3, 13,0 g/dL

    public const int PULSE_MIN_BPM = 60;    // §3.4

    public const int PULSE_MAX_BPM = 100;   // §3.4

    public const int TEMPERATURE_MAX_DC = 376;   // §3.4 — batas TEGAS, nilai ini GAGAL

    public const int SYSTOLIC_MIN_MMHG = 100;   // §3.4

    public const int SYSTOLIC_MAX_MMHG = 140;   // §3.4

    public const int DIASTOLIC_MIN_MMHG = 60;    // §3.4

    public const int DIASTOLIC_MAX_MMHG = 90;    // §3.4
}
