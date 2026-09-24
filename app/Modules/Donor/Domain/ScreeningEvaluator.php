<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

final readonly class ScreeningEvaluator
{
    /**
     * @return list<ScreeningFindingCode> kosong berarti lolos
     */
    public function evaluate(
        string $sex,                 // 'male' | 'female'
        int $haemoglobinDgDl,
        int $systolicMmhg,
        int $diastolicMmhg,
        int $pulseBpm,
        int $temperatureDc,
        float $weightKg,
        int $plannedVolumeMl,
    ): array {
        $findings = [];

        $haemoglobinMinDgDl = $sex === 'female'
            ? ScreeningLimits::HAEMOGLOBIN_MIN_FEMALE_DG_DL
            : ScreeningLimits::HAEMOGLOBIN_MIN_MALE_DG_DL;

        if ($haemoglobinDgDl < $haemoglobinMinDgDl) {
            $findings[] = ScreeningFindingCode::HAEMOGLOBIN_BELOW_MINIMUM;
        }

        if ($pulseBpm < ScreeningLimits::PULSE_MIN_BPM || $pulseBpm > ScreeningLimits::PULSE_MAX_BPM) {
            $findings[] = ScreeningFindingCode::PULSE_OUT_OF_RANGE;
        }

        if ($temperatureDc >= ScreeningLimits::TEMPERATURE_MAX_DC) {
            $findings[] = ScreeningFindingCode::TEMPERATURE_TOO_HIGH;
        }

        if ($systolicMmhg < ScreeningLimits::SYSTOLIC_MIN_MMHG
            || $systolicMmhg > ScreeningLimits::SYSTOLIC_MAX_MMHG
            || $diastolicMmhg < ScreeningLimits::DIASTOLIC_MIN_MMHG
            || $diastolicMmhg > ScreeningLimits::DIASTOLIC_MAX_MMHG) {
            $findings[] = ScreeningFindingCode::BLOOD_PRESSURE_OUT_OF_RANGE;
        }

        $weightMinKg = $plannedVolumeMl <= 350
            ? WhoEligibilityLimits::WEIGHT_MIN_350ML_KG
            : WhoEligibilityLimits::WEIGHT_MIN_450ML_KG;

        if ($weightKg < $weightMinKg) {
            $findings[] = ScreeningFindingCode::BODY_WEIGHT_BELOW_MINIMUM;
        }

        return $findings;
    }
}
