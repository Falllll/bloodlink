<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CancelBloodRequest;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\Events\BloodRequestStatusChanged;
use App\Modules\Request\Domain\RequestUrgency;
use App\Modules\Request\Infrastructure\Broadcasting\RequestStatusBroadcast;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Siaran status permintaan (kartu 490). Satu user terautentikasi per test.
 *
 * Otorisasi channel diuji di atas driver `reverb` dengan kunci dummy, BUKAN driver
 * `null` dari phpunit.xml: NullBroadcaster::auth() kosong, jadi di bawahnya setiap
 * orang "lolos" dan test otorisasi hijau tanpa membuktikan apa pun. Penandatanganan
 * Pusher-nya lokal -- tidak ada koneksi ke server Reverb.
 */
final class RequestBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    private function staffOf(Facility $facility): User
    {
        $user = User::factory()->create(['facility_id' => $facility->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $user->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    private function bloodRequest(): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            1,
            RequestUrgency::URGENT,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );
    }

    private function releasedUnit(): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells', 'facility_id' => $this->facility->id]);
        $unit->forceFill([
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'collected_at' => now()->subDays(30),
            'expires_at' => Carbon::parse('+5 days'),
            'status' => 'released',
            'released_at' => now()->subDays(29),
        ])->save();

        return $unit;
    }

    /** Driver penanda tangan sungguhan, dan channel didaftarkan ulang padanya. */
    private function authorizeWithReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');

        require base_path('routes/channels.php');
    }

    /** @return TestResponse<Response> */
    private function authorizeChannel(?User $user, Facility $facility): TestResponse
    {
        $headers = $user === null ? [] : ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];

        return $this->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-facility.'.$facility->public_id,
        ], $headers);
    }

    /** @return list<RequestStatusBroadcast> */
    private function queuedBroadcasts(): array
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        $broadcasts = [];
        foreach ($queue->pushed(BroadcastEvent::class) as $job) {
            if ($job->event instanceof RequestStatusBroadcast) {
                $broadcasts[] = $job->event;
            }
        }

        return $broadcasts;
    }

    public function test_status_change_broadcasts_on_the_owning_facility_channel(): void
    {
        // HANYA event ini yang dipalsukan: listener domain lain (reservasi, unit) tetap jalan.
        Event::fake([BloodRequestStatusChanged::class]);
        $request = $this->bloodRequest();

        app(CancelBloodRequest::class)->handle($request, 'Pasien dipindahkan');

        Event::assertDispatchedTimes(BloodRequestStatusChanged::class, 1);
        Event::assertDispatched(BloodRequestStatusChanged::class, fn (BloodRequestStatusChanged $e): bool => $e->requestId === $request->public_id
            && $e->facilityId === $this->facility->public_id
            && $e->from === 'open'
            && $e->to === 'cancelled');
    }

    /** Jalur kedua penulis status: alokasi menaikkan open -> fulfilled lewat RecomputeRequestStatus. */
    public function test_an_allocation_that_moves_the_status_emits_the_event_once(): void
    {
        Event::fake([BloodRequestStatusChanged::class]);
        $this->releasedUnit();
        $request = $this->bloodRequest();

        app(AllocateUnitsFefo::class)->handle($request, $this->staffOf($this->facility)->id);

        Event::assertDispatchedTimes(BloodRequestStatusChanged::class, 1);
        Event::assertDispatched(BloodRequestStatusChanged::class, fn (BloodRequestStatusChanged $e): bool => $e->from === 'open' && $e->to === 'fulfilled');
    }

    public function test_the_broadcast_carries_only_the_request_id_and_status_on_the_facility_channel(): void
    {
        Queue::fake();
        $request = $this->bloodRequest();

        app(CancelBloodRequest::class)->handle($request, 'Catatan rahasia');

        $broadcasts = $this->queuedBroadcasts();
        $this->assertCount(1, $broadcasts);
        [$broadcast] = $broadcasts;

        $this->assertSame('private-facility.'.$this->facility->public_id, $broadcast->broadcastOn()->name);
        $this->assertSame('blood-request.status', $broadcast->broadcastAs());
        $this->assertSame(['request_id' => $request->public_id, 'status' => 'cancelled'], $broadcast->broadcastWith());

        $payload = (string) json_encode($broadcast->broadcastWith());
        foreach ([$request->patient_reference, 'Catatan rahasia', 'negative', 'blood_group'] as $leak) {
            $this->assertStringNotContainsString($leak, $payload);
        }
    }

    /** Transaksi yang di-rollback tidak boleh menyiarkan status yang tidak pernah ada. */
    public function test_a_rolled_back_status_change_is_never_broadcast(): void
    {
        Queue::fake();
        $request = $this->bloodRequest();

        try {
            DB::transaction(function () use ($request): void {
                app(CancelBloodRequest::class)->handle($request, 'Batal');

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame('open', DB::table('blood_requests')->where('id', $request->id)->value('status'));
        $this->assertSame([], $this->queuedBroadcasts());
    }

    public function test_a_staff_member_of_the_owning_facility_is_authorized(): void
    {
        $this->authorizeWithReverb();

        $this->authorizeChannel($this->staffOf($this->facility), $this->facility)
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_a_staff_member_of_another_facility_cannot_authorize_the_channel(): void
    {
        $this->authorizeWithReverb();
        $other = Facility::factory()->create();

        $this->authorizeChannel($this->staffOf($other), $this->facility)->assertForbidden();
    }

    /** Operator global tidak punya layar operasional: satu langganan pembaca segalanya ditolak. */
    public function test_a_user_without_a_facility_cannot_authorize_a_facility_channel(): void
    {
        $this->authorizeWithReverb();

        $this->authorizeChannel(User::factory()->create(['facility_id' => null]), $this->facility)->assertForbidden();
    }

    /**
     * 401 dari auth:sanctum, bukan 403/419 dari grup 'web': bukti /broadcasting/auth
     * memakai bearer token, bukan sesi.
     */
    public function test_broadcasting_auth_rejects_a_request_without_a_bearer_token(): void
    {
        $this->authorizeWithReverb();

        $this->authorizeChannel(null, $this->facility)->assertUnauthorized();
    }
}
