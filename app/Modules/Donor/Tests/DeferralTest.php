<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\Exceptions\DeferralConflict;
use App\Modules\Donor\Application\LiftDeferral;
use App\Modules\Donor\Application\MergeDonors;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\DeferralType;
use App\Shared\Errors\ErrorCode;
use Database\Seeders\DeferralReasonSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DeferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_six_month_deferral_ends_on_the_same_day_of_month(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create();
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'POST_DELIVERY_OR_TERMINATION')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle(
            $donor,
            $reason,
            new DateTimeImmutable('2026-01-31'),
            DeferralSource::MANUAL,
        );

        $this->assertEquals(new DateTimeImmutable('2026-07-31'), $deferral->ends_at);
    }

    public function test_a_twenty_four_hour_deferral_keeps_its_time_of_day(): void
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

        $this->assertEquals(new DateTimeImmutable('2026-03-02 08:00:00'), $deferral->ends_at);
    }

    public function test_a_permanent_deferral_has_no_end_date(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create();
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'TTI_CONFIRMED_REACTIVE')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle(
            $donor,
            $reason,
            new DateTimeImmutable('2026-01-01'),
            DeferralSource::TTI_RESULT,
        );

        $this->assertSame(DeferralType::PERMANENT, $deferral->type);
        $this->assertNull($deferral->ends_at);
        $this->assertTrue($deferral->isActive());
    }

    public function test_a_permanent_deferral_cannot_be_lifted(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create();
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'TTI_CONFIRMED_REACTIVE')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle(
            $donor,
            $reason,
            new DateTimeImmutable('2026-01-01'),
            DeferralSource::TTI_RESULT,
        );

        $staff = User::factory()->create();

        try {
            (new LiftDeferral)->handle($deferral, $staff->id, 'Mencoba mencabut deferral permanen.');
            $this->fail('Expected DeferralConflict to be thrown.');
        } catch (DeferralConflict $e) {
            $this->assertSame(ErrorCode::DONOR_PERMANENT_DEFERRAL_NOT_LIFTABLE, $e->errorCode());
            $this->assertSame(409, $e->httpStatus());
        }
    }

    public function test_the_database_rejects_a_permanent_deferral_with_an_end_date(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $donor = Donor::factory()->create();
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'TTI_CONFIRMED_REACTIVE')->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('deferrals_duration_shape');

        DB::table('deferrals')->insert([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'deferral_reason_id' => $reason->id,
            'type' => 'permanent',
            'anchor_at' => now(),
            'ends_at' => now()->addDays(10),
            'source' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_donors_table_no_longer_has_the_legacy_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('donors', 'is_deferred'));
        $this->assertFalse(Schema::hasColumn('donors', 'deferred_until'));
    }

    public function test_merging_donors_moves_deferrals_to_the_target(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $facility = Facility::factory()->create();
        $source = Donor::factory()->create(['registered_facility_id' => $facility->id]);
        $target = Donor::factory()->create(['registered_facility_id' => $facility->id]);

        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'FEVER_NONSPECIFIC')->firstOrFail();

        $deferral = (new PlaceDeferral)->handle(
            $source,
            $reason,
            new DateTimeImmutable('2026-01-01'),
            DeferralSource::SCREENING,
        );

        (new MergeDonors)->handle($source, $target);

        $this->assertSame($target->id, $deferral->fresh()->donor_id);
    }
}
