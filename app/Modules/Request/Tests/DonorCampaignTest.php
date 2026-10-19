<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\DonorCampaign;
use App\Models\DonorCampaignContact;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AdvanceDonorCampaign;
use App\Modules\Request\Application\CancelBloodRequest;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Application\StartDonorCampaign;
use App\Modules\Request\Domain\CampaignEndReason;
use App\Modules\Request\Domain\Events\CampaignDonorsContacted;
use App\Modules\Request\Domain\RequestStatus;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kampanye panggilan donor berjenjang (kartu 400). Tangga default 5/15/30 km,
 * jeda default 15 menit. Titik asal Monas; 1 derajat lintang ~ 111 km.
 * Satu user terautentikasi per test; langkah peran lain lewat service.
 */
final class DonorCampaignTest extends TestCase
{
    use RefreshDatabase;

    private const float LAT = -6.1754;

    private const float LNG = 106.8272;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);
        $this->seed(DeferralReasonSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
        DB::table('facilities')->where('id', $this->facility->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT)),
        ]);
    }

    private function donorAt(float $km, ?string $email = 'donor@example.test', ?string $lastDonation = null): Donor
    {
        $donor = Donor::factory()->create();
        $donor->forceFill([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'last_donation_date' => $lastDonation,
            'email' => $email,
        ])->save();

        DB::table('donors')->where('id', $donor->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $km / 111.0)),
        ]);

        return $donor;
    }

    private function request(int $quantity = 2): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            $quantity,
            RequestUrgency::EMERGENCY,
            new DateTimeImmutable('+6 hours'),
            'RM-'.Str::random(6),
        );
    }

    private function user(string $role = 'admin', ?Facility $facility = null): User
    {
        $facility ??= $this->facility;
        $user = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function start(BloodRequest $request): DonorCampaign
    {
        return app(StartDonorCampaign::class)->handle($request, User::factory()->create());
    }

    private function advance(DonorCampaign $campaign): CampaignEndReason|int
    {
        return app(AdvanceDonorCampaign::class)->handle($campaign);
    }

    private function dwell(): void
    {
        $this->travel((int) config('matching.stage_dwell_minutes') + 1)->minutes();
    }

    private function radius(DonorCampaign $campaign): int
    {
        return DonorCampaign::query()->findOrFail($campaign->id)->current_radius_meters;
    }

    /** @return list<int> */
    private function contactedDonorIds(DonorCampaign $campaign): array
    {
        return DonorCampaignContact::query()->where('donor_campaign_id', $campaign->id)->orderBy('id')->pluck('donor_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_starting_contacts_the_first_stage_only(): void
    {
        $near = $this->donorAt(3);
        $this->donorAt(10);
        $request = $this->request();

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/campaign", [], $this->headers($this->user()))
            ->assertCreated()
            ->assertJsonPath('data.current_radius_meters', 5000)
            ->assertJsonPath('data.stages.0.radius_meters', 5000)
            ->assertJsonPath('data.totals.contacted', 1)
            ->assertJsonPath('data.end_reason', null);

        $campaign = DonorCampaign::query()->sole();
        $this->assertSame([$near->id], $this->contactedDonorIds($campaign));
    }

    public function test_advancing_before_the_dwell_changes_nothing(): void
    {
        $this->donorAt(3);
        $this->donorAt(10);
        $campaign = $this->start($this->request());

        $this->travel(5)->minutes();

        $this->assertSame(0, $this->advance($campaign));
        $this->assertSame(5000, $this->radius($campaign));
        $this->assertCount(1, $this->contactedDonorIds($campaign));
    }

    public function test_the_ladder_climbs_five_fifteen_thirty_and_never_recontacts(): void
    {
        $a = $this->donorAt(3);
        $b = $this->donorAt(10);
        $c = $this->donorAt(25);
        $this->donorAt(40);
        $campaign = $this->start($this->request(quantity: 5));

        $this->dwell();
        $this->assertSame(1, $this->advance($campaign));
        $this->assertSame(15000, $this->radius($campaign));

        $this->dwell();
        $this->assertSame(1, $this->advance($campaign));
        $this->assertSame(30000, $this->radius($campaign));

        $this->assertSame([$a->id, $b->id, $c->id], $this->contactedDonorIds($campaign));
        // Persis query DoD: donor yang dihubungi lebih dari sekali.
        $this->assertSame([], DB::select(
            'SELECT donor_id, count(*) FROM donor_campaign_contacts WHERE donor_campaign_id = ? GROUP BY 1 HAVING count(*) > 1',
            [$campaign->id],
        ));

        $this->dwell();
        $this->assertSame(CampaignEndReason::STAGES_EXHAUSTED, $this->advance($campaign));
        $this->assertSame(30000, $this->radius($campaign));
    }

    public function test_a_cancelled_request_closes_the_campaign_without_new_contacts(): void
    {
        $this->donorAt(3);
        $this->donorAt(10);
        $request = $this->request();
        $campaign = $this->start($request);

        app(CancelBloodRequest::class)->handle($request, 'Operasi dibatalkan.');
        $this->dwell();

        $this->assertSame(CampaignEndReason::REQUEST_CANCELLED, $this->advance($campaign));
        $fresh = $campaign->fresh();
        $this->assertNotNull($fresh?->ended_at);
        $this->assertSame(CampaignEndReason::REQUEST_CANCELLED, $fresh->end_reason);
        $this->assertCount(1, $this->contactedDonorIds($campaign));
    }

    public function test_a_fulfilled_request_closes_the_campaign(): void
    {
        $this->donorAt(3);
        $request = $this->request();
        $campaign = $this->start($request);

        $request->forceFill(['status' => RequestStatus::FULFILLED])->save();

        // Bahkan sebelum jeda habis: permintaan selesai menutup kampanye seketika.
        $this->assertSame(CampaignEndReason::REQUEST_FULFILLED, $this->advance($campaign));
    }

    public function test_a_fulfilled_or_closed_request_cannot_start_a_campaign(): void
    {
        $request = $this->request();
        $request->forceFill(['status' => RequestStatus::FULFILLED])->save();

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/campaign", [], $this->headers($this->user()))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CAMPAIGN_REJECTED');
    }

    public function test_a_second_campaign_for_the_same_request_is_rejected(): void
    {
        $request = $this->request();
        $this->start($request);

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/campaign", [], $this->headers($this->user()))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CAMPAIGN_REJECTED');

        $this->assertSame(1, DonorCampaign::query()->count());
    }

    public function test_the_database_allows_one_active_campaign_per_request(): void
    {
        $campaign = $this->start($this->request());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donor_campaigns_one_active_per_request');

        DB::table('donor_campaigns')->insert([
            'public_id' => (string) Str::uuid(),
            'blood_request_id' => $campaign->blood_request_id,
            'started_by' => $campaign->started_by,
            'current_radius_meters' => 5000,
            'stage_entered_at' => now(),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_contacts_a_donor_at_most_once_per_campaign(): void
    {
        $donor = $this->donorAt(3);
        $campaign = $this->start($this->request());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donor_campaign_contacts_donor_campaign_id_donor_id_unique');

        DB::table('donor_campaign_contacts')->insert([
            'public_id' => (string) Str::uuid(),
            'donor_campaign_id' => $campaign->id,
            'donor_id' => $donor->id,
            'radius_meters' => 15000,
            'distance_meters' => 3000,
            'contacted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_ties_end_reason_to_ended_at(): void
    {
        $campaign = $this->start($this->request());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('donor_campaigns_end_shape');

        DB::table('donor_campaigns')->where('id', $campaign->id)->update(['ended_at' => now()]);
    }

    public function test_only_eligible_and_reachable_donors_are_recorded(): void
    {
        $this->donorAt(1, email: null);
        $this->donorAt(2, lastDonation: now()->subDays(10)->toDateString());
        $reachable = $this->donorAt(3);

        $campaign = $this->start($this->request());

        $this->assertSame([$reachable->id], $this->contactedDonorIds($campaign));
    }

    public function test_enough_acceptances_hold_the_ladder_without_closing(): void
    {
        $this->donorAt(3);
        $this->donorAt(10);
        $campaign = $this->start($this->request(quantity: 1));
        DonorCampaignContact::query()->update(['response' => 'accepted', 'responded_at' => now()]);

        $this->dwell();

        $this->assertSame(0, $this->advance($campaign));
        $fresh = $campaign->fresh();
        $this->assertSame(5000, $fresh?->current_radius_meters);
        $this->assertNull($fresh->ended_at);
    }

    public function test_a_donor_answers_once_and_accepting_creates_no_stock(): void
    {
        $donor = $this->donorAt(3);
        $request = $this->request();
        $this->start($request);
        $contact = DonorCampaignContact::query()->sole();

        $account = User::factory()->create(['facility_id' => null]);
        $donor->forceFill(['user_id' => $account->id])->save();
        $headers = $this->headers($account);
        $batchesBefore = DB::table('blood_batches')->count();

        $this->getJson('/api/v1/me/campaign-invitations', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.id', $contact->public_id)
            ->assertJsonPath('data.0.is_open', true)
            ->assertJsonMissingPath('data.0.patient_reference');

        $this->postJson("/api/v1/me/campaign-invitations/{$contact->public_id}/respond", ['response' => 'accepted'], $headers)
            ->assertOk()
            ->assertJsonPath('data.response', 'accepted')
            ->assertJsonPath('data.is_open', false);

        $this->postJson("/api/v1/me/campaign-invitations/{$contact->public_id}/respond", ['response' => 'declined'], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CAMPAIGN_REJECTED');

        $this->assertSame($batchesBefore, DB::table('blood_batches')->count());
        $this->assertSame(RequestStatus::OPEN, $request->fresh()?->status);
    }

    /** Undangan orang lain: 404, bukan 403 -- keberadaannya tidak dibocorkan. */
    public function test_a_donor_cannot_answer_someone_elses_invitation(): void
    {
        $this->donorAt(3);
        $this->start($this->request());
        $contact = DonorCampaignContact::query()->sole();

        $stranger = User::factory()->create(['facility_id' => null]);
        $this->donorAt(50)->forceFill(['user_id' => $stranger->id])->save();

        $this->postJson("/api/v1/me/campaign-invitations/{$contact->public_id}/respond", ['response' => 'accepted'], $this->headers($stranger))
            ->assertNotFound();

        $this->assertNull($contact->fresh()?->responded_at);
    }

    public function test_the_staff_view_carries_no_donor_identity(): void
    {
        $donor = $this->donorAt(3);
        $request = $this->request();
        $this->start($request);

        $response = $this->getJson("/api/v1/blood-requests/{$request->public_id}/campaign", $this->headers($this->user()))
            ->assertOk()
            ->assertJsonPath('data.totals.contacted', 1);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($donor->public_id, $body);
        $this->assertStringNotContainsString($donor->full_name, $body);
        $this->assertStringNotContainsString($donor->phone, $body);
    }

    public function test_stopping_manually_is_once_only(): void
    {
        $this->donorAt(3);
        $campaign = $this->start($this->request());
        $headers = $this->headers($this->user());

        $this->postJson("/api/v1/campaigns/{$campaign->public_id}/stop", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.end_reason', 'stopped_manually');

        $this->postJson("/api/v1/campaigns/{$campaign->public_id}/stop", [], ['Idempotency-Key' => (string) Str::uuid()] + $headers)
            ->assertStatus(409);
    }

    public function test_hospital_staff_cannot_start_a_campaign(): void
    {
        $request = $this->request();

        $this->postJson("/api/v1/blood-requests/{$request->public_id}/campaign", [], $this->headers($this->user('hospital_staff')))
            ->assertForbidden();

        $this->assertSame(0, DonorCampaign::query()->count());
    }

    public function test_the_contact_event_carries_only_public_ids(): void
    {
        Event::fake([CampaignDonorsContacted::class]);
        $this->donorAt(3);
        $this->donorAt(4);

        $campaign = $this->start($this->request());

        Event::assertDispatched(CampaignDonorsContacted::class, fn (CampaignDonorsContacted $event): bool => $event->campaignId === $campaign->public_id
            && count($event->contactIds) === 2
            && array_keys(get_object_vars($event)) === ['campaignId', 'contactIds']);
    }

    public function test_the_dry_run_command_writes_nothing(): void
    {
        $this->donorAt(3);
        $this->donorAt(10);
        $campaign = $this->start($this->request());
        $this->dwell();

        $this->artisan('matching:advance-campaigns')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('escalate')
            ->assertSuccessful();

        $this->assertSame(5000, $this->radius($campaign));
        $this->assertCount(1, $this->contactedDonorIds($campaign));
    }

    public function test_the_forced_command_advances(): void
    {
        $this->donorAt(3);
        $this->donorAt(10);
        $campaign = $this->start($this->request());
        $this->dwell();

        $this->artisan('matching:advance-campaigns --force')->assertSuccessful();

        $this->assertSame(15000, $this->radius($campaign));
        $this->assertCount(2, $this->contactedDonorIds($campaign));
    }

    /** Dua pemanggilan beruntun: yang kedua melihat stage_entered_at yang baru dan menunggu. */
    public function test_two_runs_in_a_row_advance_once(): void
    {
        $this->donorAt(3);
        $this->donorAt(10);
        $this->donorAt(25);
        $campaign = $this->start($this->request());
        $this->dwell();

        $this->artisan('matching:advance-campaigns --force')->assertSuccessful();
        $this->artisan('matching:advance-campaigns --force')->assertSuccessful();

        $this->assertSame(15000, $this->radius($campaign));
        $this->assertCount(2, $this->contactedDonorIds($campaign));
    }
}
