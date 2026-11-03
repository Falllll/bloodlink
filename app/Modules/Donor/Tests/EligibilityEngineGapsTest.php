<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Modules\Donor\Domain\DeferralDurationUnit;
use App\Modules\Donor\Domain\DeferralType;
use App\Modules\Donor\Domain\DeferralWindow;
use App\Modules\Donor\Domain\DonorEligibilitySnapshot;
use App\Modules\Donor\Domain\DonorFlagReason;
use App\Modules\Donor\Domain\DonorFlagWindow;
use App\Modules\Donor\Domain\EligibilityDecision;
use App\Modules\Donor\Domain\EligibilityEngine;
use App\Modules\Donor\Domain\EligibilityFinding;
use App\Modules\Donor\Domain\EligibilityFindingCode;
use App\Modules\Donor\Domain\EligibilityOutcome;
use App\Modules\Donor\Domain\WhoEligibilityLimits;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Cabang mesin kelayakan yang belum pernah dieksekusi test lain (kartu 650). Unit murni:
 * tanpa database, tanpa HTTP, waktu selalu lewat $today eksplisit.
 *
 * Batas dihitung dari WhoEligibilityLimits, bukan ditulis ulang -- kecuali tanggal lahir
 * donor lanjut usia di bawah, yang SENGAJA tetap: test yang menghitung umurnya dari batas
 * akan ikut bergeser kalau batasnya diubah, dan tidak pernah merah.
 */
final class EligibilityEngineGapsTest extends TestCase
{
    private const string TODAY = '2026-06-15';

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    private function bornYearsAgo(int $years): DateTimeImmutable
    {
        return $this->today()->sub(new DateInterval("P{$years}Y"));
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        return $this->today()->sub(new DateInterval("P{$days}D"));
    }

    /**
     * Donor sehat; tiap test mengubah hanya bagian yang diuji.
     *
     * @param  list<DeferralWindow>  $deferrals
     * @param  list<DonorFlagWindow>  $flags
     */
    private function snapshot(
        ?DateTimeImmutable $dateOfBirth = null,
        string $sex = 'male',
        ?DateTimeImmutable $lastDonationDate = null,
        ?float $weightKg = 70.0,
        array $deferrals = [],
        int $plannedVolumeMl = 450,
        array $flags = [],
    ): DonorEligibilitySnapshot {
        return new DonorEligibilitySnapshot(
            dateOfBirth: $dateOfBirth ?? new DateTimeImmutable('1990-01-01'),
            sex: $sex,
            lastDonationDate: $lastDonationDate,
            weightKg: $weightKg,
            deferrals: $deferrals,
            plannedVolumeMl: $plannedVolumeMl,
            flags: $flags,
        );
    }

    private function decide(DonorEligibilitySnapshot $snapshot, ?DateTimeImmutable $today = null): EligibilityDecision
    {
        return EligibilityEngine::who()->decide($snapshot, $today ?? $this->today());
    }

    /** @return list<EligibilityFindingCode> */
    private function codes(EligibilityDecision $decision): array
    {
        return array_map(fn (EligibilityFinding $finding): EligibilityFindingCode => $finding->code, $decision->findings);
    }

    // Celah 1. Umur tidak berbalik: tidak boleh ada tanggal coba lagi.
    public function test_a_donor_above_the_maximum_age_is_rejected_without_a_retry_date(): void
    {
        $decision = $this->decide($this->snapshot(dateOfBirth: new DateTimeImmutable('1950-01-01')));

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decision->outcome);
        $this->assertSame([EligibilityFindingCode::AGE_ABOVE_MAXIMUM], $this->codes($decision));
        $this->assertNull($decision->findings[0]->retryAfterDate);
        $this->assertNull($decision->retryAfterDate);
    }

    // Celah 2. Umur dihitung tahun penuh: tepat pada hari ulang tahunnya, bukan sehari sesudahnya.
    public function test_the_age_boundaries_fall_exactly_on_the_birthday(): void
    {
        $min = WhoEligibilityLimits::AGE_MIN_USUAL_YEARS;
        $max = WhoEligibilityLimits::AGE_MAX_USUAL_YEARS;
        $oneDay = new DateInterval('P1D');

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->decide($this->snapshot(dateOfBirth: $this->bornYearsAgo($min)))->outcome);

        $dayBeforeMin = $this->decide($this->snapshot(dateOfBirth: $this->bornYearsAgo($min)), $this->today()->sub($oneDay));
        $this->assertSame([EligibilityFindingCode::AGE_BELOW_MINIMUM], $this->codes($dayBeforeMin));
        $this->assertEquals($this->today(), $dayBeforeMin->retryAfterDate);

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->decide($this->snapshot(dateOfBirth: $this->bornYearsAgo($max)))->outcome);

        // Masih berumur $max sampai sehari sebelum ulang tahun berikutnya.
        $this->assertSame(
            EligibilityOutcome::ELIGIBLE,
            $this->decide($this->snapshot(dateOfBirth: $this->bornYearsAgo($max + 1)), $this->today()->sub($oneDay))->outcome,
        );
        $this->assertSame(
            [EligibilityFindingCode::AGE_ABOVE_MAXIMUM],
            $this->codes($this->decide($this->snapshot(dateOfBirth: $this->bornYearsAgo($max + 1)))),
        );
    }

    // Celah 3. Yang sudah ada baru kasus negatif perempuan pada interval laki-laki.
    public function test_the_female_interval_is_met_on_exactly_its_last_day(): void
    {
        $days = WhoEligibilityLimits::INTERVAL_FEMALE_DAYS;

        $this->assertSame(
            EligibilityOutcome::ELIGIBLE,
            $this->decide($this->snapshot(sex: 'female', lastDonationDate: $this->daysAgo($days)))->outcome,
        );

        $dayBefore = $this->decide($this->snapshot(sex: 'female', lastDonationDate: $this->daysAgo($days - 1)));
        $this->assertSame([EligibilityFindingCode::DONATION_INTERVAL_NOT_MET], $this->codes($dayBefore));
        $this->assertEquals($this->today()->add(new DateInterval('P1D')), $dayBefore->retryAfterDate);
    }

    // Celah 4. Ambang bergantung volume. ScreeningEvaluatorTest menguji jalur skrining, bukan
    // BodyWeightRule. 350/351 adalah batas volume di aturan itu, yang tidak punya konstanta.
    public function test_the_weight_threshold_depends_on_the_planned_volume(): void
    {
        $min350 = WhoEligibilityLimits::WEIGHT_MIN_350ML_KG;
        $min450 = WhoEligibilityLimits::WEIGHT_MIN_450ML_KG;
        $justBelow450 = $min450 - 0.1;

        $cases = [
            // [berat, volume rencana, lolos?]
            [$min350, 350, true],
            [$justBelow450, 350, true],
            [$min350 - 0.1, 350, false],
            [$min350, 351, false],
            [$justBelow450, 351, false],
            [$min450, 351, true],
        ];

        foreach ($cases as [$weightKg, $volumeMl, $passes]) {
            $decision = $this->decide($this->snapshot(weightKg: $weightKg, plannedVolumeMl: $volumeMl));
            $label = "{$weightKg} kg / {$volumeMl} ml";

            if ($passes) {
                $this->assertSame(EligibilityOutcome::ELIGIBLE, $decision->outcome, $label);
            } else {
                $this->assertSame([EligibilityFindingCode::BODY_WEIGHT_BELOW_MINIMUM], $this->codes($decision), $label);
                $this->assertNull($decision->retryAfterDate, $label);
            }
        }
    }

    // Celah 5. Selama ini hanya tertutup lewat HTTP.
    public function test_an_active_admin_flag_blocks_through_the_engine(): void
    {
        $reason = DonorFlagReason::REPEATED_NO_SHOW;
        $later = $this->today()->add(new DateInterval('P10D'));
        $muchLater = $this->today()->add(new DateInterval('P20D'));

        $indefinite = $this->decide($this->snapshot(flags: [new DonorFlagWindow($reason)]));
        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $indefinite->outcome);
        $this->assertSame([EligibilityFindingCode::ACTIVE_ADMIN_FLAG], $this->codes($indefinite));
        $this->assertNull($indefinite->retryAfterDate);

        // Yang berbatas: tanggal terjauh. Satu saja tanpa batas: tidak ada tanggal.
        $timed = $this->decide($this->snapshot(flags: [new DonorFlagWindow($reason, $later), new DonorFlagWindow($reason, $muchLater)]));
        $this->assertEquals($muchLater, $timed->retryAfterDate);
        $mixed = $this->decide($this->snapshot(flags: [new DonorFlagWindow($reason, $later), new DonorFlagWindow($reason)]));
        $this->assertNull($mixed->retryAfterDate);

        // Berakhir tepat hari ini: endsAt > today salah, jadi sudah tidak aktif.
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->decide($this->snapshot(flags: [new DonorFlagWindow($reason, $this->today())]))->outcome);
    }

    // Celah 6. Satu temuan tanpa tanggal berarti tidak ada tanggal yang jujur untuk dijanjikan.
    public function test_a_dateless_finding_poisons_the_retry_date_in_either_order(): void
    {
        $dated = $this->daysAgo(1);

        // Tanpa tanggal lebih dulu (umur), lalu bertanggal (interval).
        $datelessFirst = $this->decide($this->snapshot(dateOfBirth: new DateTimeImmutable('1950-01-01'), lastDonationDate: $dated));
        $this->assertSame([EligibilityFindingCode::AGE_ABOVE_MAXIMUM, EligibilityFindingCode::DONATION_INTERVAL_NOT_MET], $this->codes($datelessFirst));
        $this->assertNotNull($datelessFirst->findings[1]->retryAfterDate);
        $this->assertNull($datelessFirst->retryAfterDate);

        // Bertanggal lebih dulu (interval), lalu tanpa tanggal (berat).
        $datedFirst = $this->decide($this->snapshot(lastDonationDate: $dated, weightKg: WhoEligibilityLimits::WEIGHT_MIN_350ML_KG - 0.1));
        $this->assertSame([EligibilityFindingCode::DONATION_INTERVAL_NOT_MET, EligibilityFindingCode::BODY_WEIGHT_BELOW_MINIMUM], $this->codes($datedFirst));
        $this->assertNotNull($datedFirst->findings[0]->retryAfterDate);
        $this->assertNull($datedFirst->retryAfterDate);
    }

    // Celah 7. NOT_ELIGIBLE mengalahkan UNDETERMINED, dan urutan temuan mengikuti urutan aturan.
    public function test_not_eligible_outranks_undetermined_and_findings_keep_the_rule_order(): void
    {
        $alone = $this->decide($this->snapshot(weightKg: null));
        $this->assertSame(EligibilityOutcome::UNDETERMINED, $alone->outcome);
        $this->assertSame([EligibilityFindingCode::DATA_MISSING], $this->codes($alone));
        $this->assertNull($alone->retryAfterDate);

        $belowMinimum = $this->bornYearsAgo(WhoEligibilityLimits::AGE_MIN_USUAL_YEARS - 1);
        $withRejection = $this->decide($this->snapshot(dateOfBirth: $belowMinimum, lastDonationDate: $this->daysAgo(1), weightKg: null));

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $withRejection->outcome);
        // Klien menampilkan temuan pertama: umur -> interval -> berat, bukan sebaliknya.
        $this->assertSame([
            EligibilityFindingCode::AGE_BELOW_MINIMUM,
            EligibilityFindingCode::DONATION_INTERVAL_NOT_MET,
            EligibilityFindingCode::DATA_MISSING,
        ], $this->codes($withRejection));

        // DATA_MISSING tidak bertanggal, tapi bukan penolakan: ia tidak boleh meracuni tanggal.
        $intervalOnly = $this->decide($this->snapshot(lastDonationDate: $this->daysAgo(1), weightKg: null));
        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $intervalOnly->outcome);
        $this->assertEquals($intervalOnly->findings[0]->retryAfterDate, $intervalOnly->retryAfterDate);
        $this->assertNotNull($intervalOnly->retryAfterDate);
    }

    // Celah 8. Dua penolakan constructor, masing-masing dengan pesannya sendiri.
    public function test_a_deferral_window_refuses_an_inconsistent_duration(): void
    {
        $build = [
            'A permanent deferral window must not carry a duration.' => fn () => new DeferralWindow('X', DeferralType::PERMANENT, $this->today(), durationValue: 1, durationUnit: DeferralDurationUnit::DAYS),
            'A temporary deferral window must have both duration fields or neither.' => fn () => new DeferralWindow('X', DeferralType::TEMPORARY, $this->today(), durationValue: 1),
        ];

        foreach ($build as $message => $construct) {
            try {
                $construct();
                $this->fail("Tidak melempar: {$message}");
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        // Satu medan saja, ke arah sebaliknya, juga ditolak.
        $this->expectExceptionMessage('A temporary deferral window must have both duration fields or neither.');
        new DeferralWindow('X', DeferralType::TEMPORARY, $this->today(), durationUnit: DeferralDurationUnit::DAYS);
    }

    // Celah 9. Durasi nol atau negatif tidak pernah menjadi spesifikasi interval.
    public function test_a_non_positive_duration_has_no_interval_spec(): void
    {
        foreach (DeferralDurationUnit::cases() as $unit) {
            foreach ([0, -1] as $value) {
                try {
                    $unit->toDateIntervalSpec($value);
                    $this->fail("Tidak melempar: {$unit->value} {$value}");
                } catch (InvalidArgumentException $e) {
                    $this->assertSame('Duration value must be a positive integer.', $e->getMessage());
                }
            }
        }
    }

    // Celah 10. Perilaku yang ada hari ini: selain 'female' memakai interval laki-laki. Kolom
    // donors.sex enum male|female, jadi nilai lain tidak sampai ke sini lewat database.
    public function test_any_sex_other_than_female_uses_the_male_interval(): void
    {
        $days = WhoEligibilityLimits::INTERVAL_MALE_DAYS;

        foreach (['male', 'other', ''] as $sex) {
            $this->assertSame(
                EligibilityOutcome::ELIGIBLE,
                $this->decide($this->snapshot(sex: $sex, lastDonationDate: $this->daysAgo($days)))->outcome,
                "sex='{$sex}'",
            );
            $this->assertSame(
                [EligibilityFindingCode::DONATION_INTERVAL_NOT_MET],
                $this->codes($this->decide($this->snapshot(sex: $sex, lastDonationDate: $this->daysAgo($days - 1)))),
                "sex='{$sex}'",
            );
        }
    }
}
