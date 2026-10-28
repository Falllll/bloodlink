<?php

declare(strict_types=1);

namespace App\Modules\Notification\Tests;

use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Modules\Notification\Application\DispatchNotification;
use App\Modules\Notification\Application\RegisterDeviceToken;
use App\Modules\Notification\Application\UpdateNotificationPreference;
use App\Modules\Notification\Domain\DeliveryStatus;
use App\Modules\Notification\Domain\DevicePlatform;
use App\Modules\Notification\Domain\NotificationCategory;
use App\Modules\Notification\Domain\NotificationChannel;
use App\Modules\Notification\Infrastructure\Jobs\SendNotification;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Preferensi per kategori (kartu 488). Satu user terautentikasi per test: guard
 * Sanctum memegang user dari request pertama meski tokennya berganti.
 */
final class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    // Kategori donation_status di seed.
    private const string OPTIONAL_CODE = 'donor.permanently_deferred';

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
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function optOut(User $user, NotificationCategory $category): void
    {
        app(UpdateNotificationPreference::class)->handle($user, $category, false);
    }

    /** @return list<string> */
    private function dispatchOptional(User $user): array
    {
        return app(DispatchNotification::class)->handle($user, self::OPTIONAL_CODE, ['donor_name' => 'Siti', 'facility_name' => 'UDD Bandung']);
    }

    public function test_every_optional_category_is_enabled_by_default(): void
    {
        $user = User::factory()->create(['facility_id' => null]);

        $response = $this->getJson('/api/v1/me/notification-preferences', $this->headers($user))
            ->assertOk()
            ->assertJsonPath('data.callable_for_emergencies', true);

        $this->assertSame(
            [
                ['category' => 'emergency_call', 'enabled' => true, 'opted_out_at' => null],
                ['category' => 'appointment_reminder', 'enabled' => true, 'opted_out_at' => null],
                ['category' => 'donation_status', 'enabled' => true, 'opted_out_at' => null],
                ['category' => 'general', 'enabled' => true, 'opted_out_at' => null],
            ],
            $response->json('data.preferences'),
        );
    }

    public function test_an_opted_out_recipient_gets_suppressed_rows_and_no_job(): void
    {
        $user = User::factory()->create();
        app(RegisterDeviceToken::class)->handle($user, 'token-1', DevicePlatform::ANDROID);
        $this->optOut($user, NotificationCategory::DONATION_STATUS);

        $ids = $this->dispatchOptional($user);

        // Tetap satu baris per alamat per kanal -- "kenapa tidak dapat kabar" harus punya jawaban.
        $this->assertCount(2, $ids);
        $rows = NotificationDelivery::query()->whereIn('public_id', $ids)->get();
        foreach ($rows as $row) {
            $this->assertSame(DeliveryStatus::SUPPRESSED, $row->status);
            $this->assertNotNull($row->settled_at);
            $this->assertNull($row->variables);
            $this->assertSame(0, $row->attempts);
        }
        $this->assertEqualsCanonicalizing(['email', 'push'], $rows->map(fn (NotificationDelivery $d): string => $d->channel->value)->all());

        Queue::assertNotPushed(SendNotification::class);
    }

    public function test_opting_out_of_one_category_leaves_the_others_alone(): void
    {
        $user = User::factory()->create();
        $this->optOut($user, NotificationCategory::GENERAL);

        [$id] = $this->dispatchOptional($user);

        $this->assertSame(DeliveryStatus::PENDING, NotificationDelivery::query()->where('public_id', $id)->sole()->status);
        Queue::assertPushed(SendNotification::class, 1);
    }

    public function test_opting_back_in_resumes_delivery(): void
    {
        $user = User::factory()->create();
        $this->optOut($user, NotificationCategory::DONATION_STATUS);
        app(UpdateNotificationPreference::class)->handle($user, NotificationCategory::DONATION_STATUS, true);

        [$id] = $this->dispatchOptional($user);

        $this->assertSame(DeliveryStatus::PENDING, NotificationDelivery::query()->where('public_id', $id)->sole()->status);
        // Kembali ikut-serta mengosongkan, tidak menghapus baris preferensinya.
        $this->assertNull(NotificationPreference::query()->where('user_id', $user->id)->sole()->opted_out_at);
    }

    /** Kategori wajib tidak pernah ditekan -- tautan reset password tetap sampai. */
    public function test_mandatory_categories_cannot_be_opted_out_of(): void
    {
        $user = User::factory()->create();

        $this->patchJson('/api/v1/me/notification-preferences/account_security', ['enabled' => false], $this->headers($user))
            ->assertNotFound();
        $this->patchJson('/api/v1/me/notification-preferences/facility_operations', ['enabled' => false], $this->headers($user))
            ->assertNotFound();

        try {
            $this->optOut($user, NotificationCategory::ACCOUNT_SECURITY);
            $this->fail('UpdateNotificationPreference seharusnya menolak kategori wajib.');
        } catch (InvalidArgumentException) {
        }

        [$id] = app(DispatchNotification::class)->handle(
            $user, 'auth.password_reset', ['reset_url' => 'https://example.test/r', 'ttl_minutes' => 60], only: [NotificationChannel::EMAIL],
        );
        $this->assertSame(DeliveryStatus::PENDING, NotificationDelivery::query()->where('public_id', $id)->sole()->status);
    }

    public function test_the_database_refuses_a_preference_for_a_mandatory_category(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('notification_preferences_category_check');

        DB::table('notification_preferences')->insert([
            'user_id' => $user->id, 'category' => 'account_security', 'opted_out_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_opting_out_of_a_category_over_http(): void
    {
        $user = User::factory()->create(['facility_id' => null]);

        $this->patchJson('/api/v1/me/notification-preferences/general', ['enabled' => false], $this->headers($user))
            ->assertOk()
            ->assertJsonPath('data.callable_for_emergencies', true)
            ->assertJsonPath('data.preferences.3.category', 'general')
            ->assertJsonPath('data.preferences.3.enabled', false)
            ->assertJsonPath('data.preferences.3.opted_out_at', now()->toAtomString());
    }

    /** Opt-out darurat = keluar dari daftar panggilan: wajib disetujui eksplisit. */
    public function test_opting_out_of_emergency_calls_requires_explicit_acknowledgement(): void
    {
        $user = User::factory()->create(['facility_id' => null]);

        foreach ([[], ['acknowledge_leaving_call_list' => false]] as $extra) {
            $this->patchJson('/api/v1/me/notification-preferences/emergency_call', ['enabled' => false] + $extra, $this->headers($user))
                ->assertStatus(422)
                ->assertJsonPath('error.details.acknowledge_leaving_call_list.0.rule', 'accepted');
        }
        $this->assertSame(0, NotificationPreference::query()->count());

        $this->patchJson('/api/v1/me/notification-preferences/emergency_call', ['enabled' => false, 'acknowledge_leaving_call_list' => true], $this->headers($user))
            ->assertOk()
            ->assertJsonPath('data.callable_for_emergencies', false)
            ->assertJsonPath('data.preferences.0.enabled', false);

        // Kembali ikut-serta tidak butuh konfirmasi.
        $this->patchJson('/api/v1/me/notification-preferences/emergency_call', ['enabled' => true], $this->headers($user))
            ->assertOk()
            ->assertJsonPath('data.callable_for_emergencies', true);
    }

    public function test_opting_out_twice_keeps_the_original_time(): void
    {
        $user = User::factory()->create();
        $this->optOut($user, NotificationCategory::GENERAL);
        $first = NotificationPreference::query()->where('user_id', $user->id)->sole()->opted_out_at;

        $this->travel(5)->minutes();
        $this->optOut($user, NotificationCategory::GENERAL);

        $this->assertEquals($first, NotificationPreference::query()->where('user_id', $user->id)->sole()->opted_out_at);
    }

    public function test_preferences_need_authentication(): void
    {
        $this->getJson('/api/v1/me/notification-preferences')->assertUnauthorized();
    }

    public function test_the_database_keeps_suppressed_rows_settled_and_empty(): void
    {
        $user = User::factory()->create();
        $base = [
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'template_code' => self::OPTIONAL_CODE, 'locale' => 'id',
            'channel' => 'email', 'status' => 'suppressed', 'queued_at' => now(), 'settled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];

        foreach ([['settled_at' => null], ['variables' => 'x'], ['attempts' => 1], ['failure_code' => 'opted_out']] as $broken) {
            try {
                DB::transaction(fn () => DB::table('notification_deliveries')->insert(['public_id' => (string) Str::uuid()] + $broken + $base));
                $this->fail('CHECK notification_deliveries_settle_shape seharusnya menolak '.json_encode($broken));
            } catch (QueryException $e) {
                $this->assertStringContainsString('notification_deliveries_settle_shape', $e->getMessage());
            }
        }

        DB::table('notification_deliveries')->insert($base);
        $this->assertSame(1, NotificationDelivery::query()->where('status', 'suppressed')->count());
    }
}
