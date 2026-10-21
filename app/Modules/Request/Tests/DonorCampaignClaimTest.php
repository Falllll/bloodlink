<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\DonorCallClaim;
use App\Models\DonorCampaignContact;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AdvanceDonorCampaign;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\StartDonorCampaign;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use App\Shared\Matching\DonorCallGuard;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\DeferralReasonSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kampanye (kartu 400) memanggil penjaga satu panggilan per donor per permintaan
 * (kartu 480) lewat port DonorCallGuard. Tangga default 5/15/30 km; titik asal
 * Monas; 1 derajat lintang ~ 111 km.
 */
final class DonorCampaignClaimTest extends TestCase
{
    use RefreshDatabase;

    private const float LAT = -6.1754;

    private const float LNG = 106.8272;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);
        $this->seed(DeferralReasonSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
        DB::table('facilities')->where('id', $this->facility->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT)),
        ]);
    }

    /** Donor layak dan bisa dihubungi, $km ke utara titik fasilitas. */
    private function donorAt(float $km): Donor
    {
        $donor = Donor::factory()->create();
        $donor->forceFill([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'last_donation_date' => null,
            'email' => 'donor@example.test',
        ])->save();

        DB::table('donors')->where('id', $donor->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $km / 111.0)),
        ]);

        return $donor;
    }

    private function request(): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            2,
            RequestUrgency::EMERGENCY,
            new DateTimeImmutable('+6 hours'),
            'RM-'.Str::random(6),
        );
    }

    private function nextStage(): void
    {
        $this->travel((int) config('matching.stage_dwell_minutes') + 1)->minutes();
    }

    public function test_a_donor_reached_at_five_km_is_not_contacted_again_at_fifteen_km(): void
    {
        $near = $this->donorAt(3);
        $far = $this->donorAt(10);
        $campaign = app(StartDonorCampaign::class)->handle($this->request(), User::factory()->create());

        $this->nextStage();
        app(AdvanceDonorCampaign::class)->handle($campaign);

        $this->assertSame(1, DonorCampaignContact::query()->where('donor_id', $near->id)->count());
        $this->assertSame(1, DonorCampaignContact::query()->where('donor_id', $far->id)->count());
        $this->assertSame(5000, DonorCallClaim::query()->where('donor_id', $near->id)->value('radius_meters'));
        $this->assertSame(15000, DonorCallClaim::query()->where('donor_id', $far->id)->value('radius_meters'));
    }

    public function test_a_restarted_campaign_for_the_same_request_does_not_call_the_same_donor_again(): void
    {
        // Celah yang tidak ditutup unique (donor_campaign_id, donor_id) kartu 400: kuncinya
        // per KAMPANYE, dan kampanye kedua untuk permintaan yang sama adalah kampanye baru.
        $donor = $this->donorAt(3);
        $request = $this->request();

        $first = app(StartDonorCampaign::class)->handle($request, User::factory()->create());
        app(AdvanceDonorCampaign::class)->stop($first);
        $second = app(StartDonorCampaign::class)->handle($request, User::factory()->create());

        $this->assertSame(1, DonorCampaignContact::query()->where('donor_id', $donor->id)->count());
        $this->assertSame(0, DonorCampaignContact::query()->where('donor_campaign_id', $second->id)->count());
        $this->assertSame(1, DonorCallClaim::query()->where('donor_id', $donor->id)->count());
    }

    public function test_a_donor_claimed_by_another_path_gets_no_contact_row(): void
    {
        // Baris kontak berarti "pesan dikirim ke orang ini": donor yang sudah diklaim
        // jalur lain tidak boleh mendapatkannya.
        $donor = $this->donorAt(3);
        $request = $this->request();
        app(DonorCallGuard::class)->claim($request->id, [(int) $donor->id], 5000, now()->toDateTimeImmutable());

        $campaign = app(StartDonorCampaign::class)->handle($request, User::factory()->create());

        $this->assertSame(0, DonorCampaignContact::query()->where('donor_campaign_id', $campaign->id)->count());
    }

    public function test_max_recipients_per_campaign_caps_what_is_claimed(): void
    {
        config(['notification.max_recipients_per_campaign' => 2]);
        foreach ([1, 2, 3, 4] as $km) {
            $this->donorAt($km);
        }

        app(StartDonorCampaign::class)->handle($this->request(), User::factory()->create());

        $this->assertSame(2, DonorCallClaim::query()->count());
        $this->assertSame(2, DonorCampaignContact::query()->count());
    }

    public function test_the_migration_lifts_existing_campaign_contacts_into_claims(): void
    {
        // Kontak yang ditulis sebelum penjaga ini ada adalah panggilan yang sudah
        // terjadi: migrasinya mengangkatnya, dengan jenjang PERTAMA per donor.
        $near = $this->donorAt(3);
        $far = $this->donorAt(10);
        $campaign = app(StartDonorCampaign::class)->handle($this->request(), User::factory()->create());
        $this->nextStage();
        app(AdvanceDonorCampaign::class)->handle($campaign);

        $migration = require database_path('migrations/2026_10_20_000700_create_donor_call_claims_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(2, DonorCallClaim::query()->count());
        $this->assertSame(5000, DonorCallClaim::query()->where('donor_id', $near->id)->value('radius_meters'));
        $this->assertSame(15000, DonorCallClaim::query()->where('donor_id', $far->id)->value('radius_meters'));
    }
}
