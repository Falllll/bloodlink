<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Modules\Donor\Domain\ScreeningEvaluator;
use App\Modules\Donor\Domain\ScreeningFindingCode;
use Tests\TestCase;

final class ScreeningEvaluatorTest extends TestCase
{
    private function evaluator(): ScreeningEvaluator
    {
        return new ScreeningEvaluator;
    }

    public function test_a_male_donor_with_haemoglobin_12_5_fails(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 125,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([ScreeningFindingCode::HAEMOGLOBIN_BELOW_MINIMUM], $findings);
    }

    public function test_a_female_donor_with_haemoglobin_12_5_passes(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'female',
            haemoglobinDgDl: 125,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([], $findings);
    }

    public function test_haemoglobin_exactly_at_the_female_threshold_passes(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'female',
            haemoglobinDgDl: 120,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([], $findings);
    }

    public function test_temperature_exactly_at_the_ceiling_fails(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 140,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 376,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([ScreeningFindingCode::TEMPERATURE_TOO_HIGH], $findings);
    }

    public function test_temperature_just_below_the_ceiling_passes(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 140,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 375,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([], $findings);
    }

    public function test_pulse_out_of_range_is_flagged(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 140,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 110,
            temperatureDc: 365,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([ScreeningFindingCode::PULSE_OUT_OF_RANGE], $findings);
    }

    public function test_blood_pressure_out_of_range_is_flagged(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 140,
            systolicMmhg: 150,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([ScreeningFindingCode::BLOOD_PRESSURE_OUT_OF_RANGE], $findings);
    }

    public function test_underweight_for_the_planned_volume_is_flagged(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 140,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 48.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([ScreeningFindingCode::BODY_WEIGHT_BELOW_MINIMUM], $findings);
    }

    public function test_the_same_weight_passes_for_a_smaller_planned_volume(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 140,
            systolicMmhg: 120,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 48.0,
            plannedVolumeMl: 350,
        );

        $this->assertSame([], $findings);
    }

    public function test_multiple_failures_are_all_returned(): void
    {
        $findings = $this->evaluator()->evaluate(
            sex: 'male',
            haemoglobinDgDl: 125,
            systolicMmhg: 150,
            diastolicMmhg: 80,
            pulseBpm: 70,
            temperatureDc: 365,
            weightKg: 70.0,
            plannedVolumeMl: 450,
        );

        $this->assertSame([
            ScreeningFindingCode::HAEMOGLOBIN_BELOW_MINIMUM,
            ScreeningFindingCode::BLOOD_PRESSURE_OUT_OF_RANGE,
        ], $findings);
    }
}
