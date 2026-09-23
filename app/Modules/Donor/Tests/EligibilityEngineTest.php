<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Modules\Donor\Domain\DeferralDurationUnit;
use App\Modules\Donor\Domain\DeferralType;
use App\Modules\Donor\Domain\DeferralWindow;
use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\EligibilityEngine;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use DateInterval;
use DateTimeImmutable;
use Tests\TestCase;

final class EligibilityEngineTest extends TestCase
{
    private function fitDonor(): DonorEligibilitySnapshot
    {
        return new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'male',
            lastDonationDate: new DateTimeImmutable('2026-01-01'),
            weightKg: 70.0,
            deferrals: [],
        );
    }

    public function test_a_fit_donor_is_eligible(): void
    {
        $decision = EligibilityEngine::who()->decide($this->fitDonor(), new DateTimeImmutable('2026-04-01'));

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decision->outcome);
        $this->assertSame([], $decision->findings);
    }

    public function test_a_missing_weight_is_undetermined_not_rejected(): void
    {
        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'male',
            lastDonationDate: new DateTimeImmutable('2026-01-01'),
            weightKg: null,
        );

        $decision = EligibilityEngine::who()->decide($snapshot, new DateTimeImmutable('2026-04-01'));

        $this->assertSame(EligibilityOutcome::UNDETERMINED, $decision->outcome);
        $this->assertCount(1, $decision->findings);
        $this->assertSame(EligibilityFindingCode::DATA_MISSING, $decision->findings[0]->code);
    }

    public function test_a_donor_who_never_donated_passes_the_interval_rule(): void
    {
        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'male',
            lastDonationDate: null,
            weightKg: 70.0,
        );

        $decision = EligibilityEngine::who()->decide($snapshot, new DateTimeImmutable('2026-04-01'));

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decision->outcome);
    }

    public function test_the_male_interval_is_met_at_exactly_84_days(): void
    {
        $lastDonation = new DateTimeImmutable('2026-01-01');

        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'male',
            lastDonationDate: $lastDonation,
            weightKg: 70.0,
        );

        $day84 = $lastDonation->add(new DateInterval('P84D'));
        $day83 = $lastDonation->add(new DateInterval('P83D'));

        $decisionAt84 = EligibilityEngine::who()->decide($snapshot, $day84);
        $decisionAt83 = EligibilityEngine::who()->decide($snapshot, $day83);

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decisionAt84->outcome);
        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decisionAt83->outcome);
    }

    public function test_the_female_interval_is_not_met_at_84_days(): void
    {
        $lastDonation = new DateTimeImmutable('2026-01-01');

        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'female',
            lastDonationDate: $lastDonation,
            weightKg: 70.0,
        );

        $day84 = $lastDonation->add(new DateInterval('P84D'));

        $decision = EligibilityEngine::who()->decide($snapshot, $day84);

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decision->outcome);
        $this->assertSame(EligibilityFindingCode::DONATION_INTERVAL_NOT_MET, $decision->findings[0]->code);
    }

    public function test_a_six_month_window_is_not_one_hundred_eighty_days(): void
    {
        $window = new DeferralWindow(
            reasonCode: 'TEST_SIX_MONTH',
            type: DeferralType::TEMPORARY,
            anchorDate: new DateTimeImmutable('2026-01-31'),
            durationValue: 6,
            durationUnit: DeferralDurationUnit::MONTHS,
        );

        $this->assertEquals(new DateTimeImmutable('2026-07-31'), $window->endsOn());
    }

    public function test_a_permanent_deferral_has_no_retry_date(): void
    {
        $window = new DeferralWindow(
            reasonCode: 'TEST_PERMANENT',
            type: DeferralType::PERMANENT,
            anchorDate: new DateTimeImmutable('2020-01-01'),
        );

        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'male',
            lastDonationDate: null,
            weightKg: 70.0,
            deferrals: [$window],
        );

        $decision = EligibilityEngine::who()->decide($snapshot, new DateTimeImmutable('2026-04-01'));

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decision->outcome);
        $this->assertNull($decision->retryAfterDate);
    }

    public function test_every_failing_rule_appears_in_findings(): void
    {
        $today = new DateTimeImmutable('2026-06-15');

        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: $today->sub(new DateInterval('P15Y')),
            sex: 'male',
            lastDonationDate: $today->sub(new DateInterval('P1D')),
            weightKg: 40.0,
        );

        $decision = EligibilityEngine::who()->decide($snapshot, $today);

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decision->outcome);
        $this->assertCount(3, $decision->findings);

        $codes = array_map(fn ($finding) => $finding->code, $decision->findings);

        $this->assertContains(EligibilityFindingCode::AGE_BELOW_MINIMUM, $codes);
        $this->assertContains(EligibilityFindingCode::BODY_WEIGHT_BELOW_MINIMUM, $codes);
        $this->assertContains(EligibilityFindingCode::DONATION_INTERVAL_NOT_MET, $codes);
    }

    public function test_the_furthest_retry_date_wins(): void
    {
        $today = new DateTimeImmutable('2026-01-01');

        $snapshot = new DonorEligibilitySnapshot(
            dateOfBirth: new DateTimeImmutable('1990-01-01'),
            sex: 'male',
            lastDonationDate: new DateTimeImmutable('2025-11-01'),
            weightKg: 70.0,
            deferrals: [
                new DeferralWindow(
                    reasonCode: 'ANTIBIOTIC_COURSE',
                    type: DeferralType::TEMPORARY,
                    anchorDate: $today,
                    durationValue: 60,
                    durationUnit: DeferralDurationUnit::DAYS,
                ),
            ],
        );

        $decision = EligibilityEngine::who()->decide($snapshot, $today);

        $intervalRetry = (new DateTimeImmutable('2025-11-01'))->add(new DateInterval('P84D'));
        $deferralRetry = $today->add(new DateInterval('P60D'));

        $this->assertTrue($deferralRetry > $intervalRetry);
        $this->assertEquals($deferralRetry, $decision->retryAfterDate);
    }
}
