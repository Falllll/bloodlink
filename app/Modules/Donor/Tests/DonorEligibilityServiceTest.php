<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\User;
use App\Modules\Donor\Application\DonorEligibilityService;
use App\Modules\Donor\Application\LiftDeferral;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\EligibilityOutcome;
use Database\Seeders\DeferralReasonSeeder;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DonorEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lifted_deferral_no_longer_blocks_the_donor(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create(['weight_kg' => 70, 'date_of_birth' => '1990-01-01']);
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'FEVER_NONSPECIFIC')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle($donor, $reason, new DateTimeImmutable('2026-01-01'), DeferralSource::SCREENING);

        $today = new DateTimeImmutable('2026-01-02');

        $decisionBefore = DonorEligibilityService::who()->for($donor->fresh(), $today);
        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decisionBefore->outcome);

        $staff = User::factory()->create();
        (new LiftDeferral)->handle($deferral, $staff->id, 'Sudah sembuh, dikonfirmasi ulang.');

        $decisionAfter = DonorEligibilityService::who()->for($donor->fresh(), $today);
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decisionAfter->outcome);
    }

    public function test_an_expired_officer_set_end_date_no_longer_blocks_the_donor(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create(['weight_kg' => 70, 'date_of_birth' => '1990-01-01']);
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'PREGNANT_OR_LACTATING')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle($donor, $reason, new DateTimeImmutable('2020-01-01'), DeferralSource::SCREENING);

        // Simulasikan petugas yang kemudian mengisi ends_at (kondisi selesai) di masa lalu.
        $deferral->forceFill(['ends_at' => now()->subDay()])->save();

        $decision = DonorEligibilityService::who()->for($donor->fresh(), new DateTimeImmutable('2026-01-01'));

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decision->outcome);
    }

    public function test_a_permanent_deferral_row_maps_without_a_duration(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create(['weight_kg' => 70]);
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'TTI_CONFIRMED_REACTIVE')->firstOrFail();

        (new PlaceDeferral)->handle($donor, $reason, new DateTimeImmutable('2026-01-01'), DeferralSource::TTI_RESULT);

        $decision = DonorEligibilityService::who()->for($donor->fresh(), new DateTimeImmutable('2026-06-01'));

        $this->assertSame(EligibilityOutcome::NOT_ELIGIBLE, $decision->outcome);
        $this->assertNull($decision->retryAfterDate);
    }

    public function test_the_snapshot_reads_the_real_columns(): void
    {
        $donor = Donor::factory()->create([
            'date_of_birth' => '1990-05-15',
            'sex' => 'female',
            'last_donation_date' => '2026-01-01',
            'weight_kg' => 70.5,
        ]);

        $snapshot = DonorEligibilityService::who()->snapshotFor($donor, new DateTimeImmutable('2026-06-01'));

        $this->assertSame('1990-05-15', $snapshot->dateOfBirth->format('Y-m-d'));
        $this->assertSame('female', $snapshot->sex);
        $this->assertSame('2026-01-01', $snapshot->lastDonationDate?->format('Y-m-d'));
        $this->assertIsFloat($snapshot->weightKg);
        $this->assertSame(70.5, $snapshot->weightKg);
    }

    public function test_the_stored_end_date_matches_the_recomputed_one(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create();
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'DENTAL_SIMPLE')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle(
            $donor,
            $reason,
            new DateTimeImmutable('2026-03-01 08:00:00'),
            DeferralSource::SCREENING,
        );

        $snapshot = DonorEligibilityService::who()->snapshotFor($donor->fresh(), new DateTimeImmutable('2026-03-01 09:00:00'));

        $window = $snapshot->deferrals[0];

        $this->assertEquals($deferral->ends_at, $window->endsOn());
    }

    public function test_the_same_donor_decided_at_three_times_of_day_gives_the_same_outcome(): void
    {
        $donor = Donor::factory()->create([
            'date_of_birth' => '1990-01-01',
            'sex' => 'male',
            'last_donation_date' => '2026-01-01',
            'weight_kg' => 70,
        ]);

        $day84 = (new DateTimeImmutable('2026-01-01'))->add(new DateInterval('P84D'));

        $service = DonorEligibilityService::who();

        $decisionMidnight = $service->for($donor->fresh(), $day84->setTime(0, 0, 0));
        $decisionMorning = $service->for($donor->fresh(), $day84->setTime(9, 0, 0));
        $decisionLastMinute = $service->for($donor->fresh(), $day84->setTime(23, 59, 0));

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decisionMidnight->outcome);
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decisionMorning->outcome);
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $decisionLastMinute->outcome);
    }

    public function test_deciding_ten_donors_does_not_run_a_query_per_donor(): void
    {
        $donors = Donor::factory()->count(10)->create(['weight_kg' => 70]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        DonorEligibilityService::who()->forMany($donors);

        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_the_service_writes_nothing(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        Donor::factory()->count(5)->create(['weight_kg' => 70]);

        $before = DB::table('donors')->orderBy('id')->get();

        $donors = Donor::all();
        DonorEligibilityService::who()->forMany($donors);

        $after = DB::table('donors')->orderBy('id')->get();

        $this->assertEquals($before, $after);
    }
}
