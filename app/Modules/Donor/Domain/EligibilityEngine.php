<?php

declare(strict_types=1);

namespace App\Modules\Donor\Domain;

use App\Modules\Donor\Domain\Rules\ActiveDeferralRule;
use App\Modules\Donor\Domain\Rules\AgeRule;
use App\Modules\Donor\Domain\Rules\BodyWeightRule;
use App\Modules\Donor\Domain\Rules\DonationIntervalRule;
use DateTimeImmutable;

final readonly class EligibilityEngine
{
    /** @param list<EligibilityRule> $rules */
    public function __construct(private array $rules) {}

    public static function who(): self
    {
        return new self([
            new AgeRule,
            new DonationIntervalRule,
            new BodyWeightRule,
            new ActiveDeferralRule,
        ]);
    }

    public function decide(
        DonorEligibilitySnapshot $snapshot,
        DateTimeImmutable $today,
    ): EligibilityDecision {
        $findings = [];

        foreach ($this->rules as $rule) {
            $finding = $rule->evaluate($snapshot, $today);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $notEligibleFindings = array_values(array_filter(
            $findings,
            fn (EligibilityFinding $finding): bool => $finding->outcome === EligibilityOutcome::NOT_ELIGIBLE,
        ));

        if ($notEligibleFindings !== []) {
            $outcome = EligibilityOutcome::NOT_ELIGIBLE;
        } elseif ($findings !== []) {
            $outcome = EligibilityOutcome::UNDETERMINED;
        } else {
            $outcome = EligibilityOutcome::ELIGIBLE;
        }

        return new EligibilityDecision(
            outcome: $outcome,
            findings: $findings,
            retryAfterDate: $notEligibleFindings !== [] ? $this->furthestRetryDate($notEligibleFindings) : null,
        );
    }

    /** @param list<EligibilityFinding> $findings */
    private function furthestRetryDate(array $findings): ?DateTimeImmutable
    {
        $furthest = null;

        foreach ($findings as $finding) {
            if ($finding->retryAfterDate === null) {
                return null;
            }

            if ($furthest === null || $finding->retryAfterDate > $furthest) {
                $furthest = $finding->retryAfterDate;
            }
        }

        return $furthest;
    }
}
