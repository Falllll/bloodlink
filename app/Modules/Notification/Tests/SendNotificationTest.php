<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Notification\Application\ChannelPolicy;
use App\Modules\Notification\Application\DispatchNotification;
use App\Modules\Notification\Application\Exceptions\ChannelNotPermitted;
use App\Modules\Notification\Application\Exceptions\DeliveryFailed;
use App\Modules\Notification\Application\Exceptions\NotificationTemplateRejected;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Application\ResolveSender;
use App\Modules\Notification\Application\RevokeDeviceToken;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\DevicePlatform;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Infrastructure\FcmNotificationSender;
use App\Modules\Notification\Infrastructure\Jobs\SendNotification;
use App\Modules\Notification\Infrastructure\Mail\RenderedNotificationMail;
use App\Modules\Notification\Infrastructure\MailNotificationSender;
use App\Modules\Notification\Tests\Concerns\FakesFcm;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\TestCase;

/**
 * Jalur kirim (kartu 460). Mail::fake() dan Http::fake() memalsukan TRANSPORT luar,
 * bukan listener yang menulis baris -- baris delivery tetap ditulis kode sungguhan.
 */
final class SendNotificationTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    private const string CODE = 'donor.permanently_deferred';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationTemplateSeeder::class);
        Mail::fake();
    }

    /** @return array<string, string> */
    private function variables(): array
    {
        return ['donor_name' => 'Siti <Aminah>', 'facility_name' => 'UDD Bandung'];
    }

    /** @return list<string> */
    private function dispatch(User $user): array
    {
        return app(DispatchNotification::class)->handle($user, self::CODE, $this->variables());
    }

    private function runQueuedJobs(): void
    {
        $queue = Queue::getFacadeRoot();
        $this->assertInstanceOf(QueueFake::class, $queue);

        foreach ($queue->pushed(SendNotification::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function device(User $user, string $token): DeviceToken
    {
        return app(RegisterDeviceToken::class)->handle($user, $token, DevicePlatform::ANDROID);
    }

    public function test_dispatch_writes_one_pending_row_per_address_before_any_job_runs(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->device($user, 'token-1');
        $this->device($user, 'token-2');

        $ids = $this->dispatch($user);

        // Satu email + dua token push; belum ada satu pun yang terkirim.
        $this->assertCount(3, $ids);
        $this->assertSame(3, NotificationDelivery::query()->where('status', 'pending')->whereNull('settled_at')->count());
        $this->assertSame(1, NotificationDelivery::query()->where('channel', 'email')->whereNull('device_token_id')->count());
        $this->assertSame(2, NotificationDelivery::query()->where('channel', 'push')->whereNotNull('device_token_id')->count());
        Mail::assertNothingSent();

        Queue::assertPushedOn(SendNotification::QUEUE, SendNotification::class);
        Queue::assertPushed(SendNotification::class, 3);
        // Kontrak antre-setelah-commit: tanpa ini worker bisa mendahului commit.
        Queue::assertPushed(SendNotification::class, fn (SendNotification $job): bool => $job->afterCommit === true);
    }

    public function test_the_job_payload_carries_only_the_public_id(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'rahasia@example.test']);

        $this->dispatch($user);

        Queue::assertPushed(SendNotification::class, function (SendNotification $job): bool {
            $payload = serialize($job);

            return ! str_contains($payload, 'rahasia@example.test')
                && ! str_contains($payload, 'Aminah')
                && ! str_contains($payload, 'NotificationDelivery');
        });
    }

    public function test_a_successful_email_job_marks_the_row_sent_and_drops_its_variables(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'donor@example.test']);

        [$id] = $this->dispatch($user);
        $this->runQueuedJobs();

        $delivery = NotificationDelivery::query()->where('public_id', $id)->sole();
        $this->assertSame(DeliveryStatus::SENT, $delivery->status);
        $this->assertNotNull($delivery->settled_at);
        $this->assertNull($delivery->failure_code);
        $this->assertNull($delivery->variables);

        Mail::assertSent(RenderedNotificationMail::class, function (RenderedNotificationMail $mail): bool {
            // Nilai variabel di-escape sebelum masuk HTML.
            return $mail->hasTo('donor@example.test')
                && $mail->hasSubject('Informasi penting tentang status donor Anda')
                && str_contains((string) $mail->render(), 'Siti &lt;Aminah&gt;');
        });
    }

    public function test_variables_are_encrypted_at_rest(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        [$id] = $this->dispatch($user);

        $raw = (string) DB::table('notification_deliveries')->where('public_id', $id)->value('variables');
        $this->assertStringNotContainsString('Aminah', $raw);
        $this->assertSame($this->variables(), NotificationDelivery::query()->where('public_id', $id)->sole()->variables);
    }

    public function test_running_the_same_job_twice_sends_once(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        [$id] = $this->dispatch($user);
        $job = new SendNotification($id);

        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);

        Mail::assertSentCount(1);
        $this->assertSame(DeliveryStatus::SENT, NotificationDelivery::query()->where('public_id', $id)->sole()->status);
    }

    public function test_a_push_job_exchanges_a_service_account_token_and_posts_to_fcm_v1(): void
    {
        Queue::fake();
        $this->fakeFcm();
        $user = User::factory()->create();
        $this->device($user, 'device-token-xyz');

        $this->dispatch($user);
        $this->runQueuedJobs();

        $this->assertSame(2, NotificationDelivery::query()->where('status', 'sent')->count());

        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::TOKEN_URI) {
                return false;
            }

            $assertion = (string) $request['assertion'];
            [, $claims] = explode('.', $assertion);
            $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && is_array($claims)
                && $claims['iss'] === 'sender@bloodlink-test.iam.gserviceaccount.com'
                && $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
                && $claims['aud'] === self::TOKEN_URI
                && $claims['exp'] - $claims['iat'] === 3600;
        });

        Http::assertSent(fn (Request $request): bool => $request->url() === self::FCM_SEND_URL
            && $request->hasHeader('Authorization', 'Bearer ya29.test')
            && $request['message']['token'] === 'device-token-xyz'
            // Push memakai template push: tanpa nama donor.
            && $request['message']['notification']['body'] === 'Ada informasi penting tentang status donor Anda. Mohon hubungi UDD Bandung.');
    }

    public function test_a_failed_send_leaves_the_row_pending_and_rethrows(): void
    {
        Queue::fake();
        $this->fakeFcm(404);
        $user = User::factory()->create();
        $device = $this->device($user, 'stale-token');

        $this->dispatch($user);
        $push = NotificationDelivery::query()->where('device_token_id', $device->id)->sole();

        try {
            app()->call([new SendNotification($push->public_id), 'handle']);
            $this->fail('DeliveryFailed was not thrown.');
        } catch (DeliveryFailed $e) {
            // 404 tanpa FcmError di details: kode tak dikenal, jadi TRANSIENT (kartu 470).
            $this->assertFalse($e->isPermanent());
            $this->assertSame('fcm_http_404', $e->failureCode);
            $this->assertStringNotContainsString('stale-token', $e->getMessage());
        }

        // Kegagalan sementara: baris tetap jejak 'pending', worker yang menjadwalkan ulang.
        $this->assertSame(DeliveryStatus::PENDING, $push->fresh()?->status);
    }

    public function test_a_revoked_token_gets_no_delivery_row(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->device($user, 'kept');
        $revoked = $this->device($user, 'revoked');
        app(RevokeDeviceToken::class)->handle($revoked);

        $this->dispatch($user);

        $this->assertSame(0, NotificationDelivery::query()->where('device_token_id', $revoked->id)->count());
        $this->assertSame(1, NotificationDelivery::query()->where('channel', 'push')->count());
    }

    public function test_a_token_that_moved_to_another_user_is_never_sent_the_old_owners_message(): void
    {
        // Worker sungguhan (antrean database): 'failed' hanya ditulis lewat fail() -> failed(),
        // dan fail() butuh job antrean yang nyata.
        config(['queue.default' => 'database']);
        $this->fakeFcm();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $device = $this->device($a, 'shared-device');

        $this->dispatch($a);
        // Perangkat berpindah tangan di antara dispatch dan eksekusi job.
        $this->device($b, 'shared-device');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => SendNotification::QUEUE, '--stop-when-empty' => true]);

        $push = NotificationDelivery::query()->where('device_token_id', $device->id)->sole();
        $this->assertSame(DeliveryStatus::FAILED, $push->status);
        $this->assertSame('recipient_unreachable', $push->failure_code);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::FCM_SEND_URL);
    }

    public function test_the_resolver_rejects_a_channel_outside_the_permitted_list(): void
    {
        $resolver = new ResolveSender(
            [app(MailNotificationSender::class), app(FcmNotificationSender::class)],
            new ChannelPolicy(['email']),
        );

        $this->assertInstanceOf(MailNotificationSender::class, $resolver->for(NotificationChannel::EMAIL));

        $this->expectException(ChannelNotPermitted::class);
        $resolver->for(NotificationChannel::PUSH);
    }

    public function test_a_variable_no_channel_declares_is_rejected_before_any_row_is_written(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        try {
            app(DispatchNotification::class)->handle($user, self::CODE, $this->variables() + ['donor_nmae' => 'typo']);
            $this->fail('NotificationTemplateRejected was not thrown.');
        } catch (NotificationTemplateRejected) {
        }

        $this->assertSame(0, NotificationDelivery::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_database_rejects_a_sent_row_carrying_a_failure_code(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('notification_deliveries_settle_shape');

        $dispatchId = DB::table('notification_dispatches')->insertGetId([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'template_code' => 'x', 'locale' => 'id',
            'dispatched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('notification_deliveries')->insert([
            'public_id' => '00000000-0000-0000-0000-000000000460',
            'notification_dispatch_id' => $dispatchId,
            'user_id' => $user->id,
            'template_code' => 'x',
            'locale' => 'id',
            'channel' => 'email',
            'status' => 'sent',
            'failure_code' => 'BOOM',
            'queued_at' => now(),
            'settled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_horizon_dashboard_is_closed_outside_the_local_environment(): void
    {
        $this->assertFalse(app()->environment('local'));

        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }
}
