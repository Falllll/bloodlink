<?php

declare(strict_types=1);

namespace App\Modules\Identity\Tests;

use App\Models\DeviceToken;
use App\Models\User;
use App\Modules\Identity\Application\IssuePasswordReset;
use App\Modules\Identity\Infrastructure\Jobs\SendPasswordResetLink;
use Database\Seeders\NotificationTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Lupa, reset, dan ganti password (kartu 485). Email dibuktikan lewat Mail::fake()
 * (transport luar) dan baris notification_deliveries -- kelas modul Notification
 * tidak diimpor dari sini (deptrac).
 */
final class PasswordResetTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    private const string FORGOT = '/api/v1/auth/forgot-password';

    private const string RESET = '/api/v1/auth/reset-password';

    private const string CHANGE = '/api/v1/auth/change-password';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(NotificationTemplateSeeder::class);
        Mail::fake();
        $this->freezeSecond();
    }

    /** @return array<string, string> */
    private function idem(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function account(string $email = 'petugas@example.test', bool $active = true): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password-lama'),
            'is_active' => $active,
        ]);
    }

    private function device(User $user, string $token): DeviceToken
    {
        $device = new DeviceToken(['platform' => 'android']);
        $device->forceFill([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token' => $token,
            'last_seen_at' => now(),
        ])->save();

        return $device;
    }

    /** @return TestResponse<JsonResponse> */
    private function forgot(string $email): TestResponse
    {
        return $this->postJson(self::FORGOT, ['email' => $email], $this->idem());
    }

    // ---------------------------------------------------------------- forgot

    public function test_forgot_password_answers_identically_for_known_and_unknown_emails(): void
    {
        Queue::fake();
        $this->account('ada@example.test');

        $known = $this->forgot('ada@example.test')->assertStatus(202)->json();
        $unknown = $this->forgot('tidak-ada@example.test')->assertStatus(202)->json();

        $this->assertSame($known['data'], $unknown['data']);
        Queue::assertPushed(SendPasswordResetLink::class, 2);
    }

    public function test_forgot_password_never_looks_the_account_up_inside_the_request(): void
    {
        // Durasi yang sama = kerja yang sama: pencarian akun terjadi di job, bukan di
        // request. Nol query ke users untuk alamat yang ada maupun tidak.
        Queue::fake();
        $this->account('ada@example.test');

        $this->assertSame(0, $this->countQueriesOn('users', fn () => $this->forgot('ada@example.test')->assertStatus(202)));
        $this->assertSame(0, $this->countQueriesOn('users', fn () => $this->forgot('tidak-ada@example.test')->assertStatus(202)));
        $this->assertSame(0, $this->countQueriesOn('user_password_resets', fn () => $this->forgot('ada@example.test')));
    }

    public function test_the_queued_job_payload_is_encrypted(): void
    {
        Queue::fake();
        $this->forgot('rahasia@example.test');

        Queue::assertPushed(SendPasswordResetLink::class, fn ($job): bool => $job instanceof ShouldBeEncrypted);
    }

    public function test_a_known_email_gets_one_reset_email_and_one_hashed_token(): void
    {
        $user = $this->account();
        // Perangkat push terdaftar: reset password TETAP email saja.
        $this->device($user, 'fcm-device-1');

        $this->forgot('petugas@example.test')->assertStatus(202);

        $this->assertSame(1, DB::table('user_password_resets')->where('user_id', $user->id)->count());
        $this->assertSame(64, strlen((string) DB::table('user_password_resets')->value('token_hash')));
        $this->assertSame(['email'], DB::table('notification_deliveries')->where('template_code', 'auth.password_reset')->pluck('channel')->all());
        Mail::assertSentCount(1);
    }

    public function test_an_unknown_or_inactive_email_sends_nothing(): void
    {
        $this->account('nonaktif@example.test', active: false);

        $this->forgot('tidak-ada@example.test')->assertStatus(202);
        $this->forgot('nonaktif@example.test')->assertStatus(202);

        $this->assertSame(0, DB::table('user_password_resets')->count());
        $this->assertSame(0, DB::table('notification_deliveries')->count());
        Mail::assertNothingSent();
    }

    public function test_one_address_is_throttled_per_hour_regardless_of_whether_it_exists(): void
    {
        Queue::fake();
        $limit = (int) config('security.rate_limit.forgot_password_per_email_per_hour');

        for ($i = 0; $i < $limit; $i++) {
            $this->forgot('Korban@Example.test')->assertStatus(202);
        }

        // Kapitalisasi lain tetap bucket yang sama.
        $this->forgot('korban@example.test')->assertStatus(429);
        // Alamat lain tidak ikut tertahan.
        $this->forgot('lain@example.test')->assertStatus(202);
        Queue::assertPushed(SendPasswordResetLink::class, $limit + 1);
    }

    public function test_forgot_password_rejects_a_malformed_email_without_saying_anything_about_accounts(): void
    {
        $this->forgot('bukan-email')->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    // ----------------------------------------------------------------- reset

    public function test_a_valid_token_resets_the_password_and_revokes_every_session_and_device(): void
    {
        $user = $this->account();
        $user->createToken('laptop');
        $user->createToken('ponsel');
        $device = $this->device($user, 'fcm-device-1');
        $token = app(IssuePasswordReset::class)->handle($user);

        $this->postJson(self::RESET, ['token' => $token, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('password-baru', $user->password));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->assertNotNull($device->refresh()->revoked_at);
        $this->assertNotNull(DB::table('user_password_resets')->where('user_id', $user->id)->value('used_at'));
    }

    public function test_the_new_password_signs_in_and_the_old_one_does_not(): void
    {
        $user = $this->account();
        $token = app(IssuePasswordReset::class)->handle($user);
        $this->postJson(self::RESET, ['token' => $token, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'petugas@example.test', 'password' => 'password-lama'], $this->idem())->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'petugas@example.test', 'password' => 'password-baru'], $this->idem())->assertOk();
    }

    public function test_a_token_works_once(): void
    {
        $token = app(IssuePasswordReset::class)->handle($this->account());
        $body = ['token' => $token, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'];

        $this->postJson(self::RESET, $body, $this->idem())->assertOk();
        $this->postJson(self::RESET, ['password' => 'password-lain', 'password_confirmation' => 'password-lain'] + $body, $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');
    }

    public function test_an_expired_token_is_rejected_with_the_same_answer_as_an_unknown_one(): void
    {
        $token = app(IssuePasswordReset::class)->handle($this->account());
        $this->travel((int) config('security.password_reset_ttl_minutes') + 1)->minutes();

        $expired = $this->postJson(self::RESET, ['token' => $token, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertStatus(422)->json('error');
        $unknown = $this->postJson(self::RESET, ['token' => Str::random(64), 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertStatus(422)->json('error');

        $this->assertSame('PASSWORD_RESET_TOKEN_INVALID', $expired['code']);
        $this->assertSame($unknown['code'], $expired['code']);
        $this->assertSame($unknown['message'], $expired['message']);
    }

    public function test_a_newer_token_invalidates_the_older_one(): void
    {
        $user = $this->account();
        $old = app(IssuePasswordReset::class)->handle($user);
        $new = app(IssuePasswordReset::class)->handle($user);

        $this->postJson(self::RESET, ['token' => $old, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertStatus(422);
        $this->postJson(self::RESET, ['token' => $new, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertOk();
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $token = app(IssuePasswordReset::class)->handle($this->account());

        $this->assertSame(0, DB::table('user_password_resets')->where('token_hash', $token)->count());
        $this->assertSame(1, DB::table('user_password_resets')->where('token_hash', hash('sha256', $token))->count());
    }

    public function test_a_token_of_a_deactivated_account_is_rejected(): void
    {
        $user = $this->account();
        $token = app(IssuePasswordReset::class)->handle($user);
        $user->forceFill(['is_active' => false])->save();

        $this->postJson(self::RESET, ['token' => $token, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PASSWORD_RESET_TOKEN_INVALID');
    }

    public function test_the_password_change_is_audited_without_the_hash(): void
    {
        $user = $this->account();
        $token = app(IssuePasswordReset::class)->handle($user);
        $this->postJson(self::RESET, ['token' => $token, 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())->assertOk();

        $changes = (string) DB::table('audit_logs')->where('auditable_type', User::class)->where('auditable_id', $user->id)->where('action', 'updated')->latest('id')->value('changes');

        $this->assertStringContainsString('[REDACTED]', $changes);
        $this->assertStringNotContainsString((string) $user->refresh()->password, $changes);
    }

    // ---------------------------------------------------------------- change

    public function test_change_password_requires_the_current_password(): void
    {
        $user = $this->account();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken] + $this->idem();

        $this->postJson(self::CHANGE, ['current_password' => 'salah', 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertTrue(Hash::check('password-lama', $user->refresh()->password));
    }

    public function test_change_password_keeps_this_session_and_signs_out_the_others(): void
    {
        $user = $this->account();
        $user->createToken('perangkat-lain');
        $current = $user->createToken('api')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$current];

        $this->postJson(self::CHANGE, ['current_password' => 'password-lama', 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $headers + $this->idem())
            ->assertOk();

        $this->assertTrue(Hash::check('password-baru', $user->refresh()->password));
        $this->assertSame(['api'], DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->pluck('name')->all());
    }

    public function test_change_password_rejects_reusing_the_current_password(): void
    {
        $user = $this->account();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken] + $this->idem();

        $this->postJson(self::CHANGE, ['current_password' => 'password-lama', 'password' => 'password-lama', 'password_confirmation' => 'password-lama'], $headers)
            ->assertStatus(422);
    }

    public function test_change_password_requires_a_token(): void
    {
        $this->postJson(self::CHANGE, ['current_password' => 'x', 'password' => 'password-baru', 'password_confirmation' => 'password-baru'], $this->idem())
            ->assertStatus(401);
    }
}
