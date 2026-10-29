<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\Events\DonorResponded;
use App\Modules\Request\Domain\RequestUrgency;
use App\Modules\Request\Infrastructure\Broadcasting\DonorResponseBroadcast;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard respons donor (kartu 500). Satu user terautentikasi per test. Kampanye
 * dan kontaknya ditulis langsung ke tabel supaya angkanya pasti; cara kampanye
 * MEMILIH donor milik DonorCampaignTest (kartu 400).
 */
final class FacilityDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    /** Nama role sengaja literal: ModRequest tidak boleh bergantung ke DomIdentity. */
    private function userOf(?Facility $facility, string $role = 'admin'): User
    {
        $user = User::factory()->create(['facility_id' => $facility?->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function requestAt(Facility $facility): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $facility->id,
            User::factory()->create(['facility_id' => $facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            2,
            RequestUrgency::EMERGENCY,
            new DateTimeImmutable('+6 hours'),
            'RM-'.Str::random(6),
        );
    }

    /** @return array{id: int, public_id: string} */
    private function campaignFor(BloodRequest $request, bool $ended = false, int $radius = 15000): array
    {
        $publicId = (string) Str::uuid();
        $id = DB::table('donor_campaigns')->insertGetId([
            'public_id' => $publicId,
            'blood_request_id' => $request->id,
            'started_by' => User::factory()->create()->id,
            'current_radius_meters' => $radius,
            'stage_entered_at' => now(),
            'started_at' => now()->subMinutes(20),
            'ended_at' => $ended ? now() : null,
            'end_reason' => $ended ? 'stopped_manually' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function contact(int $campaignId, int $radius, ?string $response = null, ?Donor $donor = null): string
    {
        $publicId = (string) Str::uuid();
        DB::table('donor_campaign_contacts')->insert([
            'public_id' => $publicId,
            'donor_campaign_id' => $campaignId,
            'donor_id' => ($donor ?? Donor::factory()->create())->id,
            'radius_meters' => $radius,
            'distance_meters' => $radius - 1000,
            'contacted_at' => now(),
            'responded_at' => $response === null ? null : now(),
            'response' => $response,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function requestOfCampaign(int $campaignId): string
    {
        return (string) DB::table('donor_campaigns as c')
            ->join('blood_requests as r', 'r.id', '=', 'c.blood_request_id')
            ->where('c.id', $campaignId)
            ->value('r.public_id');
    }

    /** @return list<BroadcastEvent> */
    private function queuedDonorResponseBroadcasts(): array
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        return array_values(array_filter(
            $queue->pushed(BroadcastEvent::class)->all(),
            fn (BroadcastEvent $job): bool => $job->event instanceof DonorResponseBroadcast,
        ));
    }

    public function test_it_returns_one_entry_per_active_campaign_with_stage_aggregates(): void
    {
        $busy = $this->requestAt($this->facility);
        $busyCampaign = $this->campaignFor($busy);
        foreach (['accepted', 'declined', null] as $response) {
            $this->contact($busyCampaign['id'], 5000, $response);
        }
        $this->contact($busyCampaign['id'], 15000, 'accepted');
        $this->contact($busyCampaign['id'], 15000);

        // Kampanye aktif yang belum menghubungi siapa pun tetap tampil -- justru itu
        // yang paling perlu dilihat petugas.
        $quietCampaign = $this->campaignFor($this->requestAt($this->facility), radius: 5000);

        $user = $this->userOf($this->facility);
        $headers = $this->headers($user);
        $data = $this->getJson('/api/v1/dashboard/campaigns', $headers)
            ->assertOk()
            ->assertJsonPath('meta.scope', 'facility')
            ->json('data');

        $this->assertCount(2, $data);
        $byId = array_column($data, null, 'id');

        $busyEntry = $byId[$busyCampaign['public_id']];
        $this->assertSame($busy->public_id, $busyEntry['blood_request_id']);
        $this->assertSame([
            ['radius_meters' => 5000, 'contacted' => 3, 'accepted' => 1, 'declined' => 1],
            ['radius_meters' => 15000, 'contacted' => 2, 'accepted' => 1, 'declined' => 0],
        ], $busyEntry['stages']);
        $this->assertSame(['contacted' => 5, 'accepted' => 2, 'declined' => 1], $busyEntry['totals']);

        $this->assertSame([], $byId[$quietCampaign['public_id']]['stages']);
        $this->assertSame(['contacted' => 0, 'accepted' => 0, 'declined' => 0], $byId[$quietCampaign['public_id']]['totals']);

        // Bentuk SAMA dengan endpoint per permintaan (kartu 400): satu parser untuk klien.
        $single = $this->getJson("/api/v1/blood-requests/{$busy->public_id}/campaign", $headers)->assertOk()->json('data');
        $this->assertSame($single, $busyEntry);
    }

    public function test_it_excludes_campaigns_that_have_ended(): void
    {
        $active = $this->campaignFor($this->requestAt($this->facility));
        $endedRequest = $this->requestAt($this->facility);
        $ended = $this->campaignFor($endedRequest, ended: true);
        $this->contact($ended['id'], 5000, 'accepted');

        $headers = $this->headers($this->userOf($this->facility));
        $ids = array_column($this->getJson('/api/v1/dashboard/campaigns', $headers)->assertOk()->json('data'), 'id');

        $this->assertSame([$active['public_id']], $ids);

        // Yang berakhir masih terbaca lewat endpoint per permintaan.
        $this->getJson("/api/v1/blood-requests/{$endedRequest->public_id}/campaign", $headers)
            ->assertOk()
            ->assertJsonPath('data.id', $ended['public_id'])
            ->assertJsonPath('data.totals.accepted', 1);
    }

    /** Data KEDUA fasilitas benar-benar ada: DB kosong tidak membuktikan penyaringan. */
    public function test_a_staff_member_of_another_facility_sees_none_of_these_campaigns(): void
    {
        $theirs = $this->campaignFor($this->requestAt($this->facility));
        $this->contact($theirs['id'], 5000, 'accepted');

        $other = Facility::factory()->create();
        $mine = $this->campaignFor($this->requestAt($other));
        $this->contact($mine['id'], 5000);

        $data = $this->getJson('/api/v1/dashboard/campaigns', $this->headers($this->userOf($other, 'hospital_staff')))
            ->assertOk()
            ->json('data');

        $this->assertSame([$mine['public_id']], array_column($data, 'id'));
        $this->assertStringNotContainsString($theirs['public_id'], (string) json_encode($data));
    }

    /** Dashboard ini berlingkup satu fasilitas: operator global tidak mendapat agregat seluruh sistem. */
    public function test_a_user_without_a_facility_gets_an_empty_dashboard(): void
    {
        $this->campaignFor($this->requestAt($this->facility));

        $this->getJson('/api/v1/dashboard/campaigns', $this->headers($this->userOf(null)))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.reason', 'no_facility');
    }

    public function test_a_donor_response_broadcasts_without_any_donor_identity(): void
    {
        Queue::fake();
        // HANYA event ini yang dipalsukan: listener lain tetap hidup.
        Event::fake([DonorResponded::class]);

        $request = $this->requestAt($this->facility);
        $campaign = $this->campaignFor($request);
        $donor = Donor::factory()->create();
        $account = User::factory()->create(['facility_id' => null]);
        $donor->forceFill(['user_id' => $account->id])->save();
        $contact = $this->contact($campaign['id'], 5000, donor: $donor);

        $this->postJson("/api/v1/me/campaign-invitations/{$contact}/respond", ['response' => 'accepted'], $this->headers($account))
            ->assertOk();

        Event::assertDispatchedTimes(DonorResponded::class, 1);
        Event::assertDispatched(DonorResponded::class, function (DonorResponded $event) use ($campaign, $request): bool {
            $broadcast = new DonorResponseBroadcast($event->campaignId, $event->bloodRequestId, $event->facilityId, $event->response);

            $this->assertSame('private-facility.'.$this->facility->public_id, $broadcast->broadcastOn()->name);
            $this->assertSame('campaign.donor-responded', $broadcast->broadcastAs());
            $this->assertSame([
                'campaign_id' => $campaign['public_id'],
                'blood_request_id' => $request->public_id,
                'response' => 'accepted',
            ], $broadcast->broadcastWith());

            return true;
        });
    }

    /** Jalur penuh event -> listener -> antrean, dan payload yang benar-benar keluar. */
    public function test_the_queued_broadcast_payload_carries_no_identity_distance_or_radius(): void
    {
        Queue::fake();
        $campaign = $this->campaignFor($this->requestAt($this->facility));
        $donor = Donor::factory()->create();
        $account = User::factory()->create(['facility_id' => null]);
        $donor->forceFill(['user_id' => $account->id])->save();
        $contact = $this->contact($campaign['id'], 5000, donor: $donor);

        $this->postJson("/api/v1/me/campaign-invitations/{$contact}/respond", ['response' => 'declined'], $this->headers($account))
            ->assertOk();

        $jobs = $this->queuedDonorResponseBroadcasts();
        $this->assertCount(1, $jobs);
        /** @var DonorResponseBroadcast $broadcast */
        $broadcast = $jobs[0]->event;
        $payload = (string) json_encode($broadcast->broadcastWith());

        // Persis tiga kunci ini, dengan nilai ini -- tidak ada ruang untuk donor_id, jarak,
        // atau tahap radius. (Bukan pencarian digit di string: angka seperti id donor
        // bisa kebetulan muncul di dalam UUID dan membuat test ini acak.)
        $this->assertSame([
            'campaign_id' => $campaign['public_id'],
            'blood_request_id' => $this->requestOfCampaign($campaign['id']),
            'response' => 'declined',
        ], $broadcast->broadcastWith());
        foreach ([$donor->public_id, $donor->full_name, $contact] as $leak) {
            $this->assertStringNotContainsString($leak, $payload);
        }
    }

    /** Jawaban yang di-rollback tidak boleh menggerakkan layar siapa pun. */
    public function test_a_rolled_back_response_is_never_broadcast(): void
    {
        Queue::fake();
        $campaign = $this->campaignFor($this->requestAt($this->facility));
        $donor = Donor::factory()->create();
        $account = User::factory()->create(['facility_id' => null]);
        $donor->forceFill(['user_id' => $account->id])->save();
        $contact = $this->contact($campaign['id'], 5000, donor: $donor);
        $headers = $this->headers($account);

        try {
            DB::transaction(function () use ($contact, $headers): void {
                $this->postJson("/api/v1/me/campaign-invitations/{$contact}/respond", ['response' => 'accepted'], $headers)->assertOk();

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertNull(DB::table('donor_campaign_contacts')->where('public_id', $contact)->value('response'));
        $this->assertSame([], $this->queuedDonorResponseBroadcasts());
    }
}
