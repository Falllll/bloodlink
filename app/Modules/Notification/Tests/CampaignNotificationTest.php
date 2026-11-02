<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\BloodRequest;
use App\Models\Donor;
use App\Models\DonorCampaign;
use App\Models\DonorCampaignContact;
use App\Models\Facility;
use App\Models\NotificationDelivery;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Modules\Notification\Application\Exceptions\NotificationTemplateRejected;
use App\Modules\Notification\Application\Listeners\SendCampaignCallNotifications;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Application\UpdateNotificationPreference;
use App\Modules\Notification\Domain\DevicePlatform;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Infrastructure\Jobs\SendNotification;
use App\Modules\Notification\Tests\Concerns\FakesFcm;
use App\Modules\Request\Domain\Events\CampaignDonorsContacted;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kampanye -> jalur kirim (kartu 540). Kampanye dan pemilihan donornya kode sungguhan;
 * yang dipalsukan hanya antrean dan transport. Titik asal Monas; 1 derajat lintang ~ 111 km.
 */
final class CampaignNotificationTest extends TestCase
{
    use FakesFcm;
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
        $this->seed(NotificationTemplateSeeder::class);

        Mail::fake();
        $this->facility = Facility::factory()->create(['name' => 'UDD Bandung']);
        DB::table('facilities')->where('id', $this->facility->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT)),
        ]);
    }

    private function donorAt(float $km, bool $withAccount = true, bool $withDevice = true): Donor
    {
        $donor = Donor::factory()->create();
        $donor->forceFill([
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'last_donation_date' => null,
            'email' => 'donor-'.Str::random(6).'@example.test',
        ])->save();

        DB::table('donors')->where('id', $donor->id)->update([
            'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography', self::LNG, self::LAT + $km / 111.0)),
        ]);

        if ($withAccount) {
            $user = User::factory()->create(['facility_id' => null]);
            $donor->forceFill(['user_id' => $user->id])->save();

            if ($withDevice) {
                app(RegisterDeviceToken::class)->handle($user, 'token-'.Str::random(10), DevicePlatform::ANDROID);
            }
        }

        return $donor;
    }

    /** Lewat tabel, bukan CreateBloodRequest: modul Notification tidak mengimpor modul Request. */
    private function bloodRequest(): BloodRequest
    {
        $id = DB::table('blood_requests')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'facility_id' => $this->facility->id,
            'patient_reference' => 'RM-RAHASIA-'.Str::random(6),
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'quantity' => 2,
            'urgency' => 'emergency',
            'status' => 'open',
            'needed_by' => now()->addHours(6),
            'requested_by' => User::factory()->create(['facility_id' => $this->facility->id])->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BloodRequest::query()->findOrFail($id);
    }

    /** Lewat API sungguhan, sebagai admin fasilitas (satu user terautentikasi per test). */
    private function startCampaign(?BloodRequest $request = null): DonorCampaign
    {
        $request ??= $this->bloodRequest();
        $staff = User::factory()->create(['facility_id' => $this->facility->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($this->facility->id));
        $staff->assignRole('admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        $id = $this->postJson("/api/v1/blood-requests/{$request->public_id}/campaign", [], [
            'Authorization' => 'Bearer '.$staff->createToken('api')->plainTextToken,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated()->json('data.id');

        return DonorCampaign::query()->where('public_id', $id)->sole();
    }

    private function queue(): QueueFake
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        return $queue;
    }

    /** Menjalankan listener yang tertahan di QueueFake, seperti worker. */
    private function runCampaignListener(): void
    {
        $jobs = $this->queue()->pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendCampaignCallNotifications::class);
        $this->assertCount(1, $jobs);

        foreach ($jobs as $job) {
            app(SendCampaignCallNotifications::class)->handle($job->data[0]);
        }
    }

    private function runDeliveryJobs(): void
    {
        foreach ($this->queue()->pushed(SendNotification::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    public function test_a_campaign_writes_one_row_and_one_job_per_address_per_donor(): void
    {
        Queue::fake();
        foreach ([1, 2, 3] as $km) {
            $this->donorAt($km);
        }

        $this->startCampaign();
        $this->runCampaignListener();

        $this->assertSame(3, DonorCampaignContact::query()->count());
        $this->assertSame(6, NotificationDelivery::query()->count());
        $this->assertSame(3, NotificationDelivery::query()->where('channel', 'email')->count());
        $this->assertSame(3, NotificationDelivery::query()->where('channel', 'push')->count());
        // Satu kejadian per donor, tiga penerima: bukan satu kejadian per kampanye.
        $this->assertSame(3, DB::table('notification_dispatches')->count());
        Queue::assertPushed(SendNotification::class, 6);
    }

    public function test_the_push_carries_the_invitation_id_of_that_donor(): void
    {
        Queue::fake();
        $this->fakeFcm();
        $donor = $this->donorAt(1);

        $this->startCampaign();
        $this->runCampaignListener();
        $this->runDeliveryJobs();

        $contact = DonorCampaignContact::query()->where('donor_id', $donor->id)->sole();
        $this->assertSame(2, NotificationDelivery::query()->where('status', 'sent')->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'messages:send')
            && $request['message']['data']['invitation_id'] === $contact->public_id
            && str_contains($request['message']['notification']['body'], 'UDD Bandung'));
    }

    /** Pesan keluar ke donor yang bukan bagian dari fasilitas mana pun. */
    public function test_the_message_carries_no_patient_reference(): void
    {
        Queue::fake();
        $this->fakeFcm();
        $this->donorAt(1);
        $request = $this->bloodRequest();

        $this->startCampaign($request);
        $this->runCampaignListener();

        $templates = NotificationTemplate::query()->where('code', SendCampaignCallNotifications::TEMPLATE)->get();
        $this->assertEqualsCanonicalizing(['email', 'push'], $templates->map(fn (NotificationTemplate $t): string => $t->channel->value)->all());
        foreach ($templates as $template) {
            $this->assertSame('emergency_call', $template->category->value);
            $this->assertSame(['facility_name'], $template->variables);
        }

        foreach (NotificationDelivery::query()->get() as $delivery) {
            $this->assertStringNotContainsString($request->patient_reference, (string) json_encode($delivery->variables));
            $this->assertStringNotContainsString($request->patient_reference, (string) json_encode($delivery->data));
        }
    }

    /** Perilaku kartu 400/488: penolak tidak mendapat baris kontak, jadi tidak ada yang dikirim. */
    public function test_a_donor_who_opted_out_gets_no_contact_and_no_delivery(): void
    {
        Queue::fake();
        $optedOut = $this->donorAt(1);
        app(UpdateNotificationPreference::class)->handle(User::query()->findOrFail($optedOut->user_id), NotificationCategory::EMERGENCY_CALL, false);
        $willing = $this->donorAt(2);

        $this->startCampaign();
        $this->runCampaignListener();

        $this->assertSame([$willing->id], DonorCampaignContact::query()->pluck('donor_id')->all());
        $this->assertSame(0, NotificationDelivery::query()->where('user_id', $optedOut->user_id)->count());
        $this->assertSame(2, NotificationDelivery::query()->where('user_id', $willing->user_id)->count());
    }

    public function test_a_donor_without_an_account_is_skipped_and_logged(): void
    {
        Queue::fake();
        $log = Log::spy();
        $this->donorAt(1, withAccount: false);
        $withAccount = $this->donorAt(2);

        $this->startCampaign();
        $this->runCampaignListener();

        $this->assertSame(2, DonorCampaignContact::query()->count());
        $this->assertSame(2, NotificationDelivery::query()->count());
        $this->assertSame(2, NotificationDelivery::query()->where('user_id', $withAccount->user_id)->count());
        $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => $message === 'campaign.contact_without_user');
    }

    /** Worker mengulang job yang gagal di tengah batch: donor yang sudah dikirimi tidak dikirimi lagi. */
    public function test_a_retried_listener_does_not_call_a_donor_twice(): void
    {
        Queue::fake();
        foreach ([1, 2] as $km) {
            $this->donorAt($km);
        }
        $this->startCampaign();

        $this->runCampaignListener();
        $this->runCampaignListener();

        $this->assertSame(4, NotificationDelivery::query()->count());
        Queue::assertPushed(SendNotification::class, 4);
    }

    /**
     * Template hilang: job listener gagal, tapi kampanye dan kontaknya tetap -- bukti ia
     * berjalan sesudah commit, bukan ikut me-rollback tahap kampanye.
     */
    public function test_a_missing_template_fails_the_listener_and_keeps_the_contacts(): void
    {
        Queue::fake();
        $this->donorAt(1);
        NotificationTemplate::query()->where('code', SendCampaignCallNotifications::TEMPLATE)->where('channel', 'push')->update(['is_active' => false]);

        $campaign = $this->startCampaign();

        try {
            $this->runCampaignListener();
            $this->fail('NotificationTemplateRejected was not thrown.');
        } catch (NotificationTemplateRejected) {
        }

        $this->assertSame(1, DonorCampaignContact::query()->where('donor_campaign_id', $campaign->id)->count());
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    /** Antrean sungguhan (sync): selama transaksi kampanye terbuka, listener belum boleh jalan. */
    public function test_the_listener_waits_for_the_campaign_transaction_to_commit(): void
    {
        $this->donorAt(1);
        $request = $this->bloodRequest();
        $insideTransaction = null;

        DB::transaction(function () use ($request, &$insideTransaction): void {
            $this->startCampaign($request);
            $insideTransaction = NotificationDelivery::query()->count();
        });

        $this->assertSame(0, $insideTransaction);
        $this->assertGreaterThan(0, NotificationDelivery::query()->count());
    }

    public function test_a_rolled_back_campaign_sends_nothing(): void
    {
        $this->donorAt(1);
        $request = $this->bloodRequest();

        try {
            DB::transaction(function () use ($request): void {
                $this->startCampaign($request);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DonorCampaignContact::query()->count());
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    /** Payload antrean listener hanya public id (bentuk event dijaga DonorCampaignTest). */
    public function test_the_queued_listener_payload_carries_no_personal_data(): void
    {
        Queue::fake();
        $donor = $this->donorAt(1);
        $this->startCampaign();

        $jobs = $this->queue()->pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === SendCampaignCallNotifications::class);
        $this->assertCount(1, $jobs);

        $event = $jobs->first()?->data[0];
        $this->assertInstanceOf(CampaignDonorsContacted::class, $event);
        $payload = serialize($event);
        $this->assertStringNotContainsString($donor->full_name, $payload);
        $this->assertStringNotContainsString((string) $donor->email, $payload);
    }
}
