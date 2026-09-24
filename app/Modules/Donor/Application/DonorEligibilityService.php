<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Deferral;
use App\Models\Donor;
use App\Modules\Donor\Domain\DeferralWindow;
use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\EligibilityDecision;
use App\Modules\Donor\Domain\EligibilityEngine;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final readonly class DonorEligibilityService
{
    public function __construct(private EligibilityEngine $engine) {}

    public static function who(): self
    {
        return new self(EligibilityEngine::who());
    }

    public function for(
        Donor $donor,
        ?DateTimeImmutable $today = null,
        int $plannedVolumeMl = 450,
    ): EligibilityDecision {
        $today ??= $this->now();

        return $this->engine->decide($this->snapshotFor($donor, $today, $plannedVolumeMl), $today);
    }

    /**
     * @param  Collection<int, Donor>  $donors
     * @return array<string, EligibilityDecision> dikunci public_id
     */
    public function forMany(
        Collection $donors,
        ?DateTimeImmutable $today = null,
        int $plannedVolumeMl = 450,
    ): array {
        $today ??= $this->now();

        $donors->loadMissing($this->deferralEagerLoad($today));

        $decisions = [];

        foreach ($donors as $donor) {
            $decisions[$donor->public_id] = $this->engine->decide(
                $this->snapshotFor($donor, $today, $plannedVolumeMl),
                $today,
            );
        }

        return $decisions;
    }

    public function snapshotFor(
        Donor $donor,
        DateTimeImmutable $today,
        int $plannedVolumeMl = 450,
    ): DonorEligibilitySnapshot {
        $donor->loadMissing($this->deferralEagerLoad($today));

        return new DonorEligibilitySnapshot(
            dateOfBirth: $donor->date_of_birth->toDateTimeImmutable(),
            sex: $donor->sex,
            lastDonationDate: $donor->last_donation_date?->toDateTimeImmutable(),
            weightKg: $donor->weight_kg === null ? null : (float) $donor->weight_kg,
            deferrals: $donor->deferrals->map(fn (Deferral $deferral): DeferralWindow => $this->toWindow($deferral))->all(),
            plannedVolumeMl: $plannedVolumeMl,
        );
    }

    /** @return array<string, callable> */
    private function deferralEagerLoad(DateTimeImmutable $today): array
    {
        return [
            'deferrals' => fn ($query) => $query
                ->whereNull('lifted_at')
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $today))
                ->with('reason:id,code'),
        ];
    }

    private function toWindow(Deferral $deferral): DeferralWindow
    {
        return new DeferralWindow(
            reasonCode: $deferral->reason->code,
            type: $deferral->type,
            anchorDate: $deferral->anchor_at->toDateTimeImmutable(),
            durationValue: $deferral->duration_value,
            durationUnit: $deferral->duration_unit,
        );
    }

    private function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }
}
