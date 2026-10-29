<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\NotificationDelivery;
use App\Models\NotificationDispatch;
use App\Models\User;
use App\Modules\Notification\Application\DispatchNotification;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Application\UpdateNotificationPreference;
use App\Modules\Notification\Domain\DevicePlatform;
use App\Modules\Notification\Domain\NotificationCategory;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Riwayat notifikasi (kartu 489). Satu user terautentikasi per test: guard Sanctum
 * memegang user dari request pertama meski tokennya berganti. Pengguna lain hanya
 * lewat service.
 */
final class NotificationHistoryTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    private const string CODE = 'donor.permanently_deferred';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationTemplateSeeder::class);
        $this->freezeSecond();
        Mail::fake();
        Queue::fake();
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    /** @return list<string> public_id delivery */
    private function notify(User $user): array
    {
        return app(DispatchNotification::class)->handle($user, self::CODE, ['donor_name' => 'Siti', 'facility_name' => 'UDD Bandung']);
    }

    /** @param  array<string, mixed>  $values */
    private function settle(string $deliveryId, array $values): void
    {
        NotificationDelivery::query()->where('public_id', $deliveryId)->update($values + ['settled_at' => now(), 'variables' => null]);
    }

    public function test_one_event_is_one_item_however_many_channels_carried_it(): void
    {
        $user = User::factory()->create(['facility_id' => null]);
        app(RegisterDeviceToken::class)->handle($user, 'token-1', DevicePlatform::ANDROID);
        app(RegisterDeviceToken::class)->handle($user, 'token-2', DevicePlatform::IOS);
        $this->notify($user);

        $response = $this->getJson('/api/v1/me/notifications', $this->headers($user))->assertOk();

        $this->assertCount(1, $response->json('data'));
        $item = $response->json('data.0');
        $this->assertSame(['id', 'template_code', 'locale', 'dispatched_at', 'outcome', 'deliveries'], array_keys($item));
        $this->assertSame(self::CODE, $item['template_code']);
        $this->assertSame(now()->toAtomString(), $item['dispatched_at']);
        $this->assertSame('pending', $item['outcome']);
        $this->assertSame(['email', 'push', 'push'], array_column($item['deliveries'], 'channel'));
        $this->assertSame(['channel', 'status', 'settled_at'], array_keys($item['deliveries'][0]));
    }

    /** Tanpa isi pesan, variabel, alamat, token, maupun galat mentah. */
    public function test_the_history_carries_no_message_body_address_or_raw_failure(): void
    {
        $user = User::factory()->create(['email' => 'donor@example.test']);
        app(RegisterDeviceToken::class)->handle($user, 'fcm-secret-token', DevicePlatform::ANDROID);
        [$email, $push] = $this->notify($user);
        $this->settle($email, ['status' => 'failed', 'failure_code' => 'smtp_rejected', 'failure_detail' => '550 relay denied by mx1.internal.example']);
        $this->settle($push, ['status' => 'sent']);

        $body = (string) $this->getJson('/api/v1/me/notifications', $this->headers($user))->assertOk()->getContent();

        foreach (['Siti', 'UDD Bandung', 'donor@example.test', 'fcm-secret-token', 'mx1.internal.example', 'smtp_rejected', 'failure', 'variables', 'user_id'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    public function test_the_outcome_separates_missed_from_never_received(): void
    {
        $user = User::factory()->create();
        app(RegisterDeviceToken::class)->handle($user, 'token-1', DevicePlatform::ANDROID);

        // Terkirim lewat satu kanal saja = "kamu melewatkannya".
        [$email, $push] = $this->notify($user);
        $this->settle($email, ['status' => 'failed', 'failure_code' => 'x']);
        $this->settle($push, ['status' => 'sent']);

        // Tidak ada kanal yang sampai = "kamu tidak pernah dapat".
        $this->travel(1)->minutes();
        foreach ($this->notify($user) as $id) {
            $this->settle($id, ['status' => 'failed', 'failure_code' => 'x']);
        }

        // Ditolak preferensi (kartu 488).
        $this->travel(1)->minutes();
        app(UpdateNotificationPreference::class)->handle($user, NotificationCategory::DONATION_STATUS, false);
        $this->notify($user);

        $outcomes = array_column($this->getJson('/api/v1/me/notifications', $this->headers($user))->assertOk()->json('data'), 'outcome');

        // Terbaru dulu.
        $this->assertSame(['suppressed', 'failed', 'delivered'], $outcomes);
    }

    public function test_only_the_signed_in_users_history_is_listed(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($other);
        $this->notify($me);

        $data = $this->getJson('/api/v1/me/notifications', $this->headers($me))->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame(NotificationDispatch::query()->where('user_id', $me->id)->sole()->public_id, $data[0]['id']);
    }

    /** Termasuk kejadian di detik yang sama: tie-breaker id menjaga kursor tidak melompat. */
    public function test_cursor_pagination_walks_every_event_once_newest_first(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->notify($user);
            if ($i % 2 === 0) {
                $this->travel(1)->minutes();
            }
        }
        $expected = NotificationDispatch::query()->where('user_id', $user->id)
            ->orderByDesc('dispatched_at')->orderBy('id')->pluck('public_id')->all();

        $headers = $this->headers($user);
        $seen = [];
        $cursor = null;
        do {
            $page = $this->getJson('/api/v1/me/notifications?per_page=2'.($cursor === null ? '' : '&cursor='.$cursor), $headers)->assertOk();
            $seen = [...$seen, ...array_column($page->json('data'), 'id')];
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null);

        $this->assertSame($expected, $seen);
    }

    public function test_per_page_is_capped_at_one_hundred(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/v1/me/notifications?per_page=500', $this->headers($user))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_events(): void
    {
        $user = User::factory()->create();
        $headers = $this->headers($user);
        $count = function (int $events) use ($user, $headers): int {
            NotificationDispatch::query()->delete();
            for ($i = 0; $i < $events; $i++) {
                $this->notify($user);
            }

            return $this->getCost('/api/v1/me/notifications', $headers);
        };

        $this->assertSame($count(2), $count(20));
    }

    public function test_history_needs_authentication(): void
    {
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    }

    /** Delivery milik user lain tidak bisa menempel pada kejadian user ini. */
    public function test_the_database_ties_a_delivery_to_its_events_owner(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $this->notify($owner);
        $dispatchId = NotificationDispatch::query()->sole()->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('notification_deliveries_dispatch_owner_fk');

        DB::table('notification_deliveries')->insert([
            'public_id' => (string) Str::uuid(), 'notification_dispatch_id' => $dispatchId, 'user_id' => $intruder->id,
            'template_code' => self::CODE, 'locale' => 'id', 'channel' => 'email', 'status' => 'pending',
            'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
