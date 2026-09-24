<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\EligibilityDecision;
use App\Modules\Donor\Domain\EligibilityEngine;
use DateTimeImmutable;
use Illuminate\Support\Carbon;

final readonly class CheckEligibilityAnonymously
{
    public function __construct(private EligibilityEngine $engine) {}

    public static function who(): self
    {
        return new self(EligibilityEngine::who());
    }

    /** @param array<string, mixed> $input */
    public function handle(array $input, ?DateTimeImmutable $today = null): EligibilityDecision
    {
        $today ??= Carbon::now()->toDateTimeImmutable();

        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable((string) $input['date_of_birth']),
            sex: (string) $input['sex'],
            lastDonationDate: isset($input['last_donation_date']) ? new DateTimeImmutable((string) $input['last_donation_date']) : null,
            weightKg: isset($input['weight_kg']) ? (float) $input['weight_kg'] : null,
            deferrals: [],
            plannedVolumeMl: isset($input['planned_volume_ml']) ? (int) $input['planned_volume_ml'] : 450,
        );

        return $this->engine->decide($snapshot, $today);
    }
}
