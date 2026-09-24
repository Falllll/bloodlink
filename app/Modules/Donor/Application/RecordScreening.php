<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\DonorScreening;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\ScreeningEvaluator;
use App\Modules\Donor\Domain\ScreeningFindingCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RecordScreening
{
    public function __construct(
        private ScreeningEvaluator $evaluator,
        private PlaceDeferral $placeDeferral,
    ) {}

    /** @param array<string, mixed> $measurements */
    public function handle(Donor $donor, array $measurements, ?int $screenedBy = null): DonorScreening
    {
        if ($donor->merged_into_id !== null) {
            throw DonorIdentityConflict::mergeConflict(
                "This donor record has been merged into {$donor->mergedInto?->public_id}."
            );
        }

        $haemoglobinDgDl = (int) round(((float) $measurements['haemoglobin_g_dl']) * 10);
        $temperatureDc = (int) round(((float) $measurements['temperature_c']) * 10);
        $systolicMmhg = (int) $measurements['systolic_mmhg'];
        $diastolicMmhg = (int) $measurements['diastolic_mmhg'];
        $pulseBpm = (int) $measurements['pulse_bpm'];
        $weightKg = (float) $measurements['weight_kg'];
        $plannedVolumeMl = isset($measurements['planned_volume_ml']) ? (int) $measurements['planned_volume_ml'] : 450;
        $note = $measurements['note'] ?? null;

        $findings = $this->evaluator->evaluate(
            sex: $donor->sex,
            haemoglobinDgDl: $haemoglobinDgDl,
            systolicMmhg: $systolicMmhg,
            diastolicMmhg: $diastolicMmhg,
            pulseBpm: $pulseBpm,
            temperatureDc: $temperatureDc,
            weightKg: $weightKg,
            plannedVolumeMl: $plannedVolumeMl,
        );

        $screenedAt = Carbon::now()->toDateTimeImmutable();

        return DB::transaction(function () use (
            $donor,
            $haemoglobinDgDl,
            $systolicMmhg,
            $diastolicMmhg,
            $pulseBpm,
            $temperatureDc,
            $weightKg,
            $plannedVolumeMl,
            $note,
            $findings,
            $screenedAt,
            $screenedBy,
        ): DonorScreening {
            $screening = new DonorScreening([
                'haemoglobin_dg_dl' => $haemoglobinDgDl,
                'systolic_mmhg' => $systolicMmhg,
                'diastolic_mmhg' => $diastolicMmhg,
                'pulse_bpm' => $pulseBpm,
                'temperature_dc' => $temperatureDc,
                'weight_kg' => $weightKg,
                'planned_volume_ml' => $plannedVolumeMl,
                'note' => $note,
            ]);

            $screening->forceFill([
                'public_id' => (string) Str::uuid(),
                'donor_id' => $donor->id,
                'facility_id' => $donor->registered_facility_id,
                'screened_by' => $screenedBy,
                'passed' => $findings === [],
                'findings' => array_map(fn (ScreeningFindingCode $code): string => $code->value, $findings),
                'screened_at' => $screenedAt,
            ])->save();

            if ($findings !== []) {
                $reason = DeferralReason::query()
                    ->where('jurisdiction', 'WHO')
                    ->where('code', $this->reasonFor($findings))
                    ->firstOrFail();

                $this->placeDeferral->handle(
                    $donor,
                    $reason,
                    $screenedAt,
                    DeferralSource::SCREENING,
                    $screenedBy,
                );
            }

            return $screening;
        });
    }

    /** @param list<ScreeningFindingCode> $findings */
    private function reasonFor(array $findings): string
    {
        if (in_array(ScreeningFindingCode::HAEMOGLOBIN_BELOW_MINIMUM, $findings, true)) {
            return 'LOW_HAEMOGLOBIN';
        }

        if (in_array(ScreeningFindingCode::BODY_WEIGHT_BELOW_MINIMUM, $findings, true)) {
            return 'UNDERWEIGHT_FOR_VOLUME';
        }

        return 'VITAL_SIGNS_OUT_OF_RANGE';
    }
}
