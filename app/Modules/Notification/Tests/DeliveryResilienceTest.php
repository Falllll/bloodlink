<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\DeviceToken;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Modules\Notification\Application\DispatchNotification;
use App\Modules\Notification\Application\Exceptions\DeliveryFailed;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\DevicePlatform;
use App\Modules\Notification\Domain\RenderedNotification;
use App\Modules\Notification\Infrastructure\FcmNotificationSender;
use App\Modules\Notification\Infrastructure\Jobs\SendNotification;
use App\Modules\Notification\Infrastructure\MailNotificationSender;
use App\Modules\Notification\Tests\Concerns\FakesFcm;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\MailFake;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Ketahanan pengiriman (kartu 470). Retry dan dead-letter diuji lewat WORKER
 * sungguhan atas antrean database, bukan memanggil handle() langsung: $this->fail(),
 * pelepasan dengan backoff, dan failed() hanya bekerja lewat job antrean yang nyata.
 */
final class DeliveryResilienceTest extends TestCase
{
    use FakesFcm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationTemplateSeeder::class);
        Mail::fake();
        config(['queue.default' => 'database']);
    }

    /** Satu pengguna, satu token push. Email-nya ikut terkirim lewat Mail::fake(). */
    private function dispatchToDevice(string $token = 'device-token'): NotificationDelivery
    {
        $user = User::factory()->create();
        $device = app(RegisterDeviceToken::class)->handle($user, $token, DevicePlatform::ANDROID);

        app(DispatchNotification::class)->handle($user, 'donor.permanently_deferred', ['donor_name' => 'Siti', 'facility_name' => 'UDD Bandung']);

        return NotificationDelivery::query()->where('device_token_id', $device->id)->sole();
    }

    private function work(): void
    {
        // --sleep=0: tanpa itu worker tidur 3 detik setelah antrean kosong, dan jeda
        // yang diukur test ikut menyusut sebanyak itu.
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => SendNotification::QUEUE, '--stop-when-empty' => true, '--sleep' => 0]);
    }

    private function fcmSendCount(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === self::FCM_SEND_URL)->count();
    }

    public function test_a_transient_failure_is_rescheduled_with_backoff_not_failed(): void
    {
        $this->fakeFcm(503, 'UNAVAILABLE');
        $push = $this->dispatchToDevice();

        $this->work();

        $push->refresh();
        $this->assertSame(DeliveryStatus::PENDING, $push->status);
        $this->assertSame(1, $push->attempts);
        $this->assertNotNull($push->last_attempt_at);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        // Job-nya kembali ke antrean, TERTUNDA: jeda pertama 10 detik +/- 30%.
        $job = DB::table('jobs')->where('queue', SendNotification::QUEUE)->sole();
        $this->assertSame(1, (int) $job->attempts);
        // Diukur dari awal percobaan (last_attempt_at), bukan dari jam test sesudahnya.
        $this->assertNotNull($push->last_attempt_at);
        $delay = (int) $job->available_at - $push->last_attempt_at->getTimestamp();
        $this->assertGreaterThanOrEqual(6, $delay);
        $this->assertLessThanOrEqual(13, $delay);
    }

    public function test_attempts_grow_per_attempt_and_exhaustion_dead_letters_with_the_last_code(): void
    {
        config(['notification.delivery_max_attempts' => 3]);
        $this->fakeFcm(503, 'UNAVAILABLE');
        $push = $this->dispatchToDevice();

        foreach ([1, 2] as $expected) {
            $this->work();
            $this->assertSame($expected, $push->refresh()->attempts);
            $this->assertSame(DeliveryStatus::PENDING, $push->status);
            $this->travel(2)->minutes();
        }

        $this->work();

        $push->refresh();
        $this->assertSame(3, $push->attempts);
        $this->assertSame(DeliveryStatus::FAILED, $push->status);
        $this->assertSame('fcm_unavailable', $push->failure_code);
        $this->assertNotNull($push->settled_at);
        $this->assertNull($push->variables);
        $this->assertSame(3, $this->fcmSendCount());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_permanent_failure_dead_letters_on_the_first_attempt(): void
    {
        $this->fakeFcm(404, 'UNREGISTERED');
        $push = $this->dispatchToDevice();

        $this->work();

        $push->refresh();
        $this->assertSame(DeliveryStatus::FAILED, $push->status);
        $this->assertSame('fcm_unregistered', $push->failure_code);
        $this->assertSame(1, $push->attempts);
        $this->assertSame(1, $this->fcmSendCount());
        // Tidak ada sisa percobaan yang menunggu di antrean.
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_an_unregistered_token_is_revoked_and_never_selected_again(): void
    {
        $this->fakeFcm(404, 'UNREGISTERED');
        $push = $this->dispatchToDevice('dead-token');

        $this->work();

        $device = DeviceToken::query()->findOrFail($push->device_token_id);
        $this->assertNotNull($device->revoked_at);
        $this->assertFalse(DeviceToken::query()->deliverable()->whereKey($device->id)->exists());

        // Kampanye berikutnya tidak lagi membuat baris push untuk token mati itu.
        Queue::fake();
        app(DispatchNotification::class)->handle($device->user, 'donor.permanently_deferred', ['donor_name' => 'Siti', 'facility_name' => 'UDD Bandung']);
        $this->assertSame(1, NotificationDelivery::query()->where('device_token_id', $device->id)->count());
    }

    public function test_a_permanent_failure_that_is_not_about_the_token_keeps_the_token(): void
    {
        // SENDER_ID_MISMATCH bisa berarti FCM_PROJECT_ID kita yang salah: mencabut
        // berarti menghapus seluruh registri.
        $this->fakeFcm(403, 'SENDER_ID_MISMATCH');
        $push = $this->dispatchToDevice();

        $this->work();

        $this->assertSame('fcm_sender_id_mismatch', $push->refresh()->failure_code);
        $this->assertNull(DeviceToken::query()->findOrFail($push->device_token_id)->revoked_at);
    }

    /** @return iterable<string, array{int, string|null, bool, string}> */
    public static function fcmReplies(): iterable
    {
        yield 'UNREGISTERED' => [404, 'UNREGISTERED', true, 'fcm_unregistered'];
        yield 'INVALID_ARGUMENT' => [400, 'INVALID_ARGUMENT', true, 'fcm_invalid_argument'];
        yield 'SENDER_ID_MISMATCH' => [403, 'SENDER_ID_MISMATCH', true, 'fcm_sender_id_mismatch'];
        yield 'THIRD_PARTY_AUTH_ERROR' => [401, 'THIRD_PARTY_AUTH_ERROR', true, 'fcm_third_party_auth_error'];
        yield 'QUOTA_EXCEEDED' => [429, 'QUOTA_EXCEEDED', false, 'fcm_quota_exceeded'];
        yield 'UNAVAILABLE' => [503, 'UNAVAILABLE', false, 'fcm_unavailable'];
        yield 'INTERNAL' => [500, 'INTERNAL', false, 'fcm_internal'];
        yield 'UNSPECIFIED_ERROR' => [500, 'UNSPECIFIED_ERROR', false, 'fcm_unspecified_error'];
        // Kode yang belum dikenal: TRANSIENT, bukan menyerah.
        yield 'unknown code' => [400, 'SOMETHING_NEW_IN_2027', false, 'fcm_something_new_in_2027'];
        yield 'no FcmError detail' => [502, null, false, 'fcm_http_502'];
    }

    #[DataProvider('fcmReplies')]
    public function test_fcm_replies_are_classified(int $status, ?string $fcmCode, bool $permanent, string $failureCode): void
    {
        $this->fakeFcm($status, $fcmCode);

        try {
            app(FcmNotificationSender::class)->send('token-x', new RenderedNotification(null, 'Isi'));
            $this->fail('DeliveryFailed was not thrown.');
        } catch (DeliveryFailed $e) {
            $this->assertSame($permanent, $e->isPermanent());
            $this->assertSame($failureCode, $e->failureCode);
            $this->assertStringNotContainsString('token-x', $e->getMessage());
        }
    }

    public function test_an_unreachable_fcm_is_transient(): void
    {
        $this->fakeFcm();
        Http::fake([self::FCM_SEND_URL => Http::failedConnection()]);

        try {
            app(FcmNotificationSender::class)->send('token-x', new RenderedNotification(null, 'Isi'));
            $this->fail('DeliveryFailed was not thrown.');
        } catch (DeliveryFailed $e) {
            $this->assertFalse($e->isPermanent());
            $this->assertSame('fcm_unreachable', $e->failureCode);
        }
    }

    /** @return iterable<string, array{int, bool, string}> */
    public static function smtpReplies(): iterable
    {
        yield '550 mailbox unavailable' => [550, true, 'smtp_550'];
        yield '551 user not local' => [551, true, 'smtp_551'];
        yield '553 mailbox name not allowed' => [553, true, 'smtp_553'];
        yield '451 local error' => [451, false, 'smtp_451'];
        yield '421 service unavailable' => [421, false, 'smtp_421'];
        yield '535 auth failed (our side)' => [535, false, 'smtp_535'];
        yield '554 ambiguous' => [554, false, 'smtp_554'];
    }

    /** Mailer sungguhan (bukan Mail::fake) di atas transport yang menolak dengan $error. */
    private function mailTransportFailingWith(TransportExceptionInterface $error): void
    {
        $this->useRealMailer();
        Mail::extend('rejecting', fn (): AbstractTransport => new class($error) extends AbstractTransport
        {
            public function __construct(private readonly TransportExceptionInterface $error)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw $this->error;
            }

            public function __toString(): string
            {
                return 'rejecting://';
            }
        });
        config(['mail.mailers.rejecting' => ['transport' => 'rejecting'], 'mail.default' => 'rejecting']);
    }

    /** Mail::fake() menukar binding 'mail.manager' di container; kembalikan manager aslinya. */
    private function useRealMailer(): void
    {
        $root = Mail::getFacadeRoot();
        Mail::swap($root instanceof MailFake ? $root->manager : $root);
    }

    #[DataProvider('smtpReplies')]
    public function test_smtp_replies_are_classified(int $code, bool $permanent, string $failureCode): void
    {
        $this->mailTransportFailingWith(new UnexpectedResponseException("Expected response code \"250\" but got code \"{$code}\", with message \"x@example.test rejected\".", $code));

        try {
            app(MailNotificationSender::class)->send('x@example.test', new RenderedNotification('Subjek', 'Isi'));
            $this->fail('DeliveryFailed was not thrown.');
        } catch (DeliveryFailed $e) {
            $this->assertSame($permanent, $e->isPermanent());
            $this->assertSame($failureCode, $e->failureCode);
            $this->assertStringNotContainsString('x@example.test', $e->getMessage());
        }
    }

    public function test_an_unreachable_smtp_host_is_transient(): void
    {
        // Transport SMTP SUNGGUHAN ke port yang tidak mendengarkan: kabelnya, bukan simulasi.
        $this->useRealMailer();
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
        ]);

        try {
            app(MailNotificationSender::class)->send('x@example.test', new RenderedNotification('Subjek', 'Isi'));
            $this->fail('DeliveryFailed was not thrown.');
        } catch (DeliveryFailed $e) {
            $this->assertFalse($e->isPermanent());
            $this->assertSame('smtp_unreachable', $e->failureCode);
        }
    }

    public function test_backoff_grows_and_never_drops_below_one_second(): void
    {
        foreach (range(1, 50) as $_) {
            $delays = (new SendNotification('x'))->backoff();

            // 5 percobaan = 4 jeda sebelum percobaan ke-2..ke-5.
            $this->assertCount(4, $delays);
            for ($i = 1; $i < count($delays); $i++) {
                $this->assertGreaterThan($delays[$i - 1], $delays[$i]);
            }
        }

        // Jitter ekstrem atas base terkecil tidak pernah menghasilkan jeda 0.
        config(['notification.delivery_base_backoff_seconds' => 1, 'notification.delivery_jitter_ratio' => 0.999]);
        foreach (range(1, 50) as $_) {
            $this->assertGreaterThanOrEqual(1, min((new SendNotification('x'))->backoff()));
        }
    }

    public function test_two_backoff_calls_produce_different_delays(): void
    {
        // Bukti jitter benar-benar terpasang. Peluang dua deret 4 jeda acak kembar
        // dengan rasio 0.3 di bawah 1:100.000.
        $this->assertNotSame((new SendNotification('x'))->backoff(), (new SendNotification('x'))->backoff());
    }

    public function test_the_longest_total_backoff_stays_below_retry_after(): void
    {
        // Dua angka di dua file, dibandingkan oleh test -- bukan oleh ingatan.
        $tries = (int) config('notification.delivery_max_attempts');
        $base = (int) config('notification.delivery_base_backoff_seconds');
        $ratio = (float) config('notification.delivery_jitter_ratio');
        $maxTotal = (int) ceil($base * (2 ** ($tries - 1) - 1) * (1 + $ratio));
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertSame(195, $maxTotal);
        $this->assertLessThan($retryAfter, $maxTotal);
        // Dan job yang sedang BERJALAN tidak boleh dianggap hilang.
        $this->assertLessThan($retryAfter, (int) config('horizon.defaults.supervisor-1.timeout'));
    }

    public function test_failed_writes_a_dead_letter_row_that_passes_the_settle_shape_check(): void
    {
        Queue::fake();
        $push = $this->dispatchToDevice();

        (new SendNotification($push->public_id))->failed(DeliveryFailed::transient('smtp_unreachable', 'SMTP transport rejected the message (reply code 0).'));

        $push->refresh();
        $this->assertSame(DeliveryStatus::FAILED, $push->status);
        $this->assertSame('smtp_unreachable', $push->failure_code);
        $this->assertSame('SMTP transport rejected the message (reply code 0).', $push->failure_detail);
        $this->assertNotNull($push->settled_at);
        $this->assertNull($push->variables);
    }

    public function test_failed_with_a_foreign_exception_records_only_its_class(): void
    {
        Queue::fake();
        $push = $this->dispatchToDevice();

        (new SendNotification($push->public_id))->failed(new RuntimeException('donor siti@example.test'));

        $push->refresh();
        $this->assertSame('runtime_exception', $push->failure_code);
        $this->assertSame(RuntimeException::class, $push->failure_detail);
    }

    public function test_failed_never_overwrites_a_sent_row(): void
    {
        Queue::fake();
        $this->fakeFcm();
        $push = $this->dispatchToDevice();
        app()->call([new SendNotification($push->public_id), 'handle']);

        (new SendNotification($push->public_id))->failed(DeliveryFailed::transient('late', 'x'));

        $this->assertSame(DeliveryStatus::SENT, $push->refresh()->status);
        $this->assertNull($push->failure_code);
    }

    public function test_the_failed_report_reports_zero_and_groups_failures(): void
    {
        $this->artisan('notification:failed-report')
            ->expectsOutputToContain('0 failed notification deliveries')
            ->assertSuccessful();

        Queue::fake();
        $push = $this->dispatchToDevice();
        (new SendNotification($push->public_id))->failed(DeliveryFailed::permanent('fcm_unregistered', 'x'));

        $this->artisan('notification:failed-report', ['--since' => '1h'])
            ->expectsOutputToContain('1 failed notification deliveries')
            ->expectsTable(['failure_code', 'channel', 'total'], [['fcm_unregistered', 'push', 1]])
            ->assertSuccessful();

        $this->artisan('notification:failed-report', ['--since' => 'yesterday'])->assertFailed();
    }
}
