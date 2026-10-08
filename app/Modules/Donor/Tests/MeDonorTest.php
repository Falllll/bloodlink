<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Appointment;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Domain\AppointmentStatus;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MeDonorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /** Nama role sengaja literal: ModDonor tidak boleh bergantung ke DomIdentity. */
    private function assignRole(User $user, string $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($user->facility_id));
        $user->assignRole($role);

        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        $token = $user->createToken('api')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    /** Akun donor seperti yang dibuat AuthController::register(): tanpa fasilitas. */
    private function donorAccount(): User
    {
        $user = User::factory()->create(['facility_id' => null]);
        $this->assignRole($user, 'donor');

        return $user;
    }

    /** Penautan user_id dikerjakan staf; di sini ditulis langsung karena di luar $fillable. */
    private function linkedDonor(User $user): Donor
    {
        $donor = Donor::factory()->create(['registered_facility_id' => Facility::factory()->create()->id]);
        $donor->forceFill(['user_id' => $user->id])->save();

        return $donor;
    }

    /** Donasi ditulis langsung seperti RecordDonation menulisnya, satu janji temu per donasi. */
    private function donationFor(Donor $donor, Carbon $completedAt): Donation
    {
        $appointment = new Appointment(['scheduled_for' => null]);
        $appointment->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'status' => AppointmentStatus::COMPLETED,
            'arrived_at' => $completedAt->copy()->subHour(),
            'screened_at' => $completedAt->copy()->subMinutes(30),
            'completed_at' => $completedAt,
        ])->save();

        $donation = new Donation([
            'volume_ml' => 450,
            'started_at' => $completedAt->copy()->subMinutes(10),
            'completed_at' => $completedAt,
            'note' => null,
        ]);
        $donation->forceFill([
            'public_id' => (string) Str::uuid(),
            'donor_id' => $donor->id,
            'facility_id' => $donor->registered_facility_id,
            'appointment_id' => $appointment->id,
        ])->save();

        return $donation;
    }

    public function test_a_linked_donor_reads_their_own_profile(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);

        $this->getJson('/api/v1/me/donor', $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.id', $donor->public_id)
            ->assertJsonPath('data.donor_number', $donor->donor_number);
    }

    public function test_the_profile_never_contains_the_internal_id_nik_or_hashes(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $donor->forceFill(['nik' => '3171234567890123'])->save();

        $response = $this->getJson('/api/v1/me/donor', $this->bearer($user))->assertOk();

        $response->assertJsonPath('data.id', $donor->public_id)
            ->assertJsonPath('data.has_nik', true)
            ->assertJsonMissingPath('data.nik')
            ->assertJsonMissingPath('data.nik_hash')
            ->assertJsonMissingPath('data.phone_hash')
            ->assertJsonMissingPath('data.user_id');
        $this->assertNotSame($donor->id, $response->json('data.id'));
    }

    public function test_the_history_contains_only_the_donors_own_donations(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $own = [
            $this->donationFor($donor, Carbon::parse('2026-03-01 09:00:00')),
            $this->donationFor($donor, Carbon::parse('2026-06-01 09:00:00')),
        ];

        $other = Donor::factory()->create(['registered_facility_id' => Facility::factory()->create()->id]);
        $foreign = $this->donationFor($other, Carbon::parse('2026-07-01 09:00:00'));

        $response = $this->getJson('/api/v1/me/donor/donations', $this->bearer($user));

        // assertOk() lebih dulu: kalau visibleTo() menyelinap masuk, kegagalannya
        // harus terbaca sebagai "200 tapi kosong", bukan sebagai error.
        $response->assertOk()
            ->assertJsonStructure(['data', 'links' => ['next', 'prev'], 'meta' => ['per_page', 'next_cursor', 'prev_cursor', 'has_more']])
            ->assertJsonCount(2, 'data');

        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$own[0]->public_id, $own[1]->public_id], $ids);
        $this->assertNotContains($foreign->public_id, $ids);
        $this->assertSame([$donor->public_id], array_values(array_unique(array_column($response->json('data'), 'donor_id'))));
    }

    public function test_the_history_never_contains_collected_by_or_numeric_ids(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $donation = $this->donationFor($donor, Carbon::parse('2026-06-01 09:00:00'));
        $donation->forceFill(['collected_by' => $user->id])->save();

        $response = $this->getJson('/api/v1/me/donor/donations', $this->bearer($user))->assertOk();

        $row = $response->json('data.0');
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('collected_by', $row);
        $this->assertArrayNotHasKey('facility_id', $row);

        foreach (['id', 'donor_id', 'appointment_id'] as $key) {
            $this->assertTrue(Str::isUuid($row[$key]), "{$key} must be a public UUID");
        }
    }

    public function test_the_history_is_newest_first(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $oldest = $this->donationFor($donor, Carbon::parse('2025-01-10 09:00:00'));
        $newest = $this->donationFor($donor, Carbon::parse('2026-05-10 09:00:00'));
        $middle = $this->donationFor($donor, Carbon::parse('2025-09-10 09:00:00'));

        $response = $this->getJson('/api/v1/me/donor/donations', $this->bearer($user))->assertOk();

        $this->assertSame(
            [$newest->public_id, $middle->public_id, $oldest->public_id],
            array_column($response->json('data'), 'id'),
        );
    }

    public function test_identical_completed_at_pages_through_every_donation_exactly_once_by_id_desc(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $at = Carbon::parse('2026-04-01 09:00:00');

        // Lebih dari satu halaman (20): tanpa tie-breaker id, kursor hanya memuat
        // completed_at, dan halaman berikutnya melewati semua baris kembarnya.
        $expected = [];
        for ($i = 0; $i < 25; $i++) {
            $expected[] = $this->donationFor($donor, $at);
        }
        usort($expected, fn (Donation $a, Donation $b): int => $b->id <=> $a->id);

        $headers = $this->bearer($user);
        $seen = [];
        $url = '/api/v1/me/donor/donations';
        for ($guard = 0; $url !== null && $guard < 5; $guard++) {
            $response = $this->getJson($url, $headers)->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $cursor = $response->json('meta.next_cursor');
            $url = $cursor === null ? null : '/api/v1/me/donor/donations?cursor='.$cursor;
        }

        $this->assertSame(array_map(fn (Donation $d): string => $d->public_id, $expected), $seen);
    }

    public function test_the_second_page_is_reachable_with_the_cursor_from_the_first_page(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);
        $start = Carbon::parse('2026-01-01 09:00:00');

        // 25 > 20 per halaman, jadi halaman 2 memang ada.
        $all = [];
        for ($i = 0; $i < 25; $i++) {
            $all[] = $this->donationFor($donor, $start->copy()->addDays($i))->public_id;
        }

        $headers = $this->bearer($user);

        $first = $this->getJson('/api/v1/me/donor/donations', $headers)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.has_more', true);

        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->assertStringContainsString('cursor='.$cursor, (string) $first->json('links.next'));

        $second = $this->getJson('/api/v1/me/donor/donations?cursor='.$cursor, $headers)
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $firstIds = array_column($first->json('data'), 'id');
        $secondIds = array_column($second->json('data'), 'id');

        $this->assertSame([], array_intersect($firstIds, $secondIds));
        $this->assertEqualsCanonicalizing($all, array_merge($firstIds, $secondIds));
        $this->assertSame(array_reverse($all), array_merge($firstIds, $secondIds));
    }

    public function test_an_account_without_a_linked_donor_gets_404_not_linked(): void
    {
        $user = $this->donorAccount();

        $this->getJson('/api/v1/me/donor', $this->bearer($user))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');
    }

    public function test_the_history_of_an_unlinked_account_is_404_not_an_empty_list(): void
    {
        $user = $this->donorAccount();

        $this->getJson('/api/v1/me/donor/donations', $this->bearer($user))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');
    }

    public function test_a_merged_donor_row_is_404(): void
    {
        $user = $this->donorAccount();
        $source = $this->linkedDonor($user);
        $target = Donor::factory()->create(['registered_facility_id' => $source->registered_facility_id]);
        $source->forceFill(['merged_into_id' => $target->id, 'merged_at' => now()])->save();

        $this->getJson('/api/v1/me/donor', $this->bearer($user))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');
    }

    public function test_the_profile_requires_a_token(): void
    {
        $this->getJson('/api/v1/me/donor')->assertStatus(401);
    }

    public function test_the_history_requires_a_token(): void
    {
        $this->getJson('/api/v1/me/donor/donations')->assertStatus(401);
    }

    public function test_a_linked_donor_account_is_still_forbidden_on_the_staff_donor_endpoint(): void
    {
        $user = $this->donorAccount();
        $donor = $this->linkedDonor($user);

        $this->getJson("/api/v1/donors/{$donor->public_id}", $this->bearer($user))
            ->assertStatus(403);
    }
}
