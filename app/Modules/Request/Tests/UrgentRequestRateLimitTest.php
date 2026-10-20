<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodRequest;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Limiter `urgent-requests` (kartu 420): dua bucket per JAM, per petugas dan per
 * fasilitas. Closure limiter membaca config pada setiap permintaan, jadi menurunkan
 * angkanya lewat config()->set() di dalam test langsung berlaku tanpa membangun
 * ulang limiter.
 *
 * Banyak pengguna per test lewat actingAs(): setUser eksplisit, bukan token Bearer
 * (token kedua di test yang sama diam-diam berjalan sebagai pengguna pertama).
 */
final class UrgentRequestRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Id fasilitas yang SENGAJA sama dengan id petugas kedua: tanpa prefix di ->by(),
     * bucket petugas itu dan bucket fasilitasnya jadi satu bucket.
     */
    private const int COLLIDING_ID = 9001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        // Hitungan limiter tinggal di cache: tanpa ini test berikutnya mewarisi
        // sisa test sebelumnya dan kelihatan seperti bug limiter.
        Cache::flush();
    }

    private function staff(Facility $facility, ?int $id = null): User
    {
        $user = User::factory()->create(['facility_id' => $facility->id] + ($id === null ? [] : ['id' => $id]));

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility->id));
        $user->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(string $urgency = 'emergency'): array
    {
        return [
            'patient_reference' => 'RM-'.Str::random(6),
            'component' => 'packed_red_cells',
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'quantity' => 1,
            'urgency' => $urgency,
            'needed_by' => now()->addHours(2)->toIso8601String(),
        ];
    }

    /** @return TestResponse<Response> */
    private function urgent(User $as): TestResponse
    {
        $this->actingAs($as, 'sanctum');

        return $this->postJson('/api/v1/blood-requests/urgent', $this->payload(), ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function limits(int $perUser, int $perFacility): void
    {
        config()->set('security.rate_limit.urgent_per_user_per_hour', $perUser);
        config()->set('security.rate_limit.urgent_per_facility_per_hour', $perFacility);
    }

    public function test_the_same_staff_member_is_stopped_with_429_and_retry_after(): void
    {
        $this->limits(perUser: 1, perFacility: 20);
        $staff = $this->staff(Facility::factory()->create());

        $this->urgent($staff)->assertCreated();

        $this->urgent($staff)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS')
            ->assertHeader('Retry-After');

        $this->assertSame(1, BloodRequest::query()->withoutGlobalScopes()->count());
    }

    /** Dua bucket, bukan satu bucket per fasilitas yang menghukum petugas kedua. */
    public function test_a_second_staff_member_at_the_same_facility_can_still_send(): void
    {
        $this->limits(perUser: 1, perFacility: 20);
        $facility = Facility::factory()->create();
        $first = $this->staff($facility);
        $second = $this->staff($facility);

        $this->urgent($first)->assertCreated();
        $this->urgent($first)->assertStatus(429);

        $this->urgent($second)->assertCreated();

        $this->assertSame(
            [$first->id, $second->id],
            BloodRequest::query()->withoutGlobalScopes()->orderBy('id')->pluck('requested_by')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    /**
     * PENJAGA PREFIX. Tabrakan id dibuat sengaja: petugas ber-id 9001 di fasilitas LAIN,
     * sementara fasilitas 9001 sudah memakai bucket-nya. Tanpa prefix di ->by(), bucket
     * pengguna "9001" dan bucket fasilitas "9001" adalah satu bucket.
     *
     * Tabrakan harus LINTAS permintaan: kalau dua key kembar muncul di SATU permintaan
     * (petugas 9001 di fasilitas 9001), Laravel 13 sendiri memisahkannya lewat
     * Limit::fallbackKey() (RateLimiter::limiter), jadi kasus itu tidak bisa menggigit.
     */
    public function test_a_user_id_equal_to_another_facility_id_does_not_share_its_bucket(): void
    {
        $this->limits(perUser: 1, perFacility: 1);
        $busy = Facility::factory()->create(['id' => self::COLLIDING_ID]);
        $first = $this->staff($busy);
        $namesake = $this->staff(Facility::factory()->create(), self::COLLIDING_ID);

        $this->urgent($first)->assertCreated();

        $this->urgent($namesake)->assertCreated();
    }

    public function test_a_second_staff_member_is_held_once_the_facility_bucket_is_spent(): void
    {
        $this->limits(perUser: 5, perFacility: 2);
        $facility = Facility::factory()->create();
        $first = $this->staff($facility);
        $second = $this->staff($facility);

        $this->urgent($first)->assertCreated();
        $this->urgent($first)->assertCreated();

        $this->urgent($second)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_another_facility_is_not_held_by_the_first(): void
    {
        $this->limits(perUser: 5, perFacility: 1);
        $first = $this->staff(Facility::factory()->create());
        $elsewhere = $this->staff(Facility::factory()->create());

        $this->urgent($first)->assertCreated();
        $this->urgent($first)->assertStatus(429);

        $this->urgent($elsewhere)->assertCreated();
    }

    public function test_a_plain_request_is_not_held_by_the_urgent_bucket(): void
    {
        $this->limits(perUser: 1, perFacility: 1);
        $staff = $this->staff(Facility::factory()->create());

        $this->urgent($staff)->assertCreated();
        $this->urgent($staff)->assertStatus(429);

        $this->actingAs($staff, 'sanctum');
        $this->postJson('/api/v1/blood-requests', $this->payload('routine'), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated();
    }

    /** Fallback tanpa fasilitas tetap per akun: dua akun tanpa fasilitas tidak berbagi bucket. */
    public function test_accounts_without_a_facility_do_not_share_one_bucket(): void
    {
        $this->limits(perUser: 5, perFacility: 1);
        $one = User::factory()->create(['facility_id' => null]);
        $two = User::factory()->create(['facility_id' => null]);

        // Limiter berjalan sebelum policy: 403 (bukan 429) berarti bucket-nya masih longgar.
        $this->urgent($one)->assertForbidden();
        $this->urgent($two)->assertForbidden();
    }
}
