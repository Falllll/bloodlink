<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\Deferral;
use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Domain\DeferralSource;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Satu user terautentikasi per test: dalam satu test, guard Sanctum tetap
 * memegang user request pertama meski token berganti.
 */
final class DeferralEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeferralReasonSeeder::class);
    }

    /** Nama role sengaja literal: ModDonor tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function headers(User $user, bool $withKey = true): array
    {
        return array_merge(
            ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken],
            $withKey ? ['Idempotency-Key' => (string) Str::uuid()] : [],
        );
    }

    private function donor(): Donor
    {
        return Donor::factory()->create(['registered_facility_id' => Facility::factory()->create()->id]);
    }

    private function placed(Donor $donor, string $code): Deferral
    {
        $reason = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', $code)->firstOrFail();

        return (new PlaceDeferral)->handle($donor, $reason, new DateTimeImmutable('2026-01-01'), DeferralSource::SCREENING);
    }

    public function test_staff_places_a_manual_deferral_through_the_domain_service(): void
    {
        $donor = $this->donor();
        $staff = $this->userOf($donor->registered_facility_id);

        $response = $this->postJson("/api/v1/donors/{$donor->public_id}/deferrals", [
            'reason_code' => 'TATTOO_PIERCING_ACUPUNCTURE',
            'anchor_at' => '2026-03-01T08:00:00+00:00',
            'note' => 'Tato baru di lengan kiri.',
        ], $this->headers($staff));

        $response->assertCreated()
            ->assertJsonPath('data.donor_id', $donor->public_id)
            ->assertJsonPath('data.reason_code', 'TATTOO_PIERCING_ACUPUNCTURE')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.type', 'temporary');

        $deferral = Deferral::query()->sole();
        $this->assertSame($staff->id, $deferral->placed_by);
        $this->assertNotNull($deferral->ends_at); // dihitung PlaceDeferral, bukan oleh klien
    }

    public function test_an_inactive_reason_is_a_409_conflict_from_the_domain(): void
    {
        DeferralReason::query()->where('code', 'TATTOO_PIERCING_ACUPUNCTURE')->update(['is_active' => false]);
        $donor = $this->donor();

        $this->postJson("/api/v1/donors/{$donor->public_id}/deferrals", [
            'reason_code' => 'TATTOO_PIERCING_ACUPUNCTURE',
            'anchor_at' => '2026-03-01T08:00:00+00:00',
        ], $this->headers($this->userOf($donor->registered_facility_id)))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_DEFERRAL_CONFLICT');

        $this->assertSame(0, Deferral::query()->count());
    }

    public function test_an_unknown_reason_code_is_a_validation_error(): void
    {
        $donor = $this->donor();

        $this->postJson("/api/v1/donors/{$donor->public_id}/deferrals", [
            'reason_code' => 'MADE_UP_CODE',
            'anchor_at' => '2026-03-01T08:00:00+00:00',
        ], $this->headers($this->userOf($donor->registered_facility_id)))
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason_code.0.rule', 'exists');
    }

    public function test_a_temporary_deferral_is_lifted_once_and_only_once(): void
    {
        $donor = $this->donor();
        $deferral = $this->placed($donor, 'PREGNANT_OR_LACTATING');
        $staff = $this->userOf($donor->registered_facility_id);

        $this->patchJson("/api/v1/deferrals/{$deferral->public_id}/lift", ['reason' => 'Selesai menyusui.'], $this->headers($staff))
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.lift_note', 'Selesai menyusui.');

        $this->patchJson("/api/v1/deferrals/{$deferral->public_id}/lift", ['reason' => 'Lagi.'], $this->headers($staff))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_DEFERRAL_ALREADY_LIFTED');
    }

    public function test_a_permanent_deferral_cannot_be_lifted(): void
    {
        $donor = $this->donor();
        $deferral = $this->placed($donor, 'TTI_CONFIRMED_REACTIVE');

        $this->patchJson("/api/v1/deferrals/{$deferral->public_id}/lift", ['reason' => 'Coba cabut.'], $this->headers($this->userOf($donor->registered_facility_id)))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DONOR_PERMANENT_DEFERRAL_NOT_LIFTABLE');

        $this->assertNull($deferral->fresh()?->lifted_at);
    }

    public function test_placing_a_deferral_needs_an_idempotency_key(): void
    {
        $donor = $this->donor();

        $this->postJson("/api/v1/donors/{$donor->public_id}/deferrals", [
            'reason_code' => 'TATTOO_PIERCING_ACUPUNCTURE',
            'anchor_at' => '2026-03-01T08:00:00+00:00',
        ], $this->headers($this->userOf($donor->registered_facility_id), withKey: false))
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, Deferral::query()->count());
    }

    public function test_lifting_a_deferral_needs_an_idempotency_key(): void
    {
        $donor = $this->donor();
        $deferral = $this->placed($donor, 'PREGNANT_OR_LACTATING');

        $this->patchJson("/api/v1/deferrals/{$deferral->public_id}/lift", ['reason' => 'Selesai.'], $this->headers($this->userOf($donor->registered_facility_id), withKey: false))
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertNull($deferral->fresh()?->lifted_at);
    }

    public function test_the_history_lists_lifted_rows_and_can_be_narrowed_to_active(): void
    {
        $donor = $this->donor();
        $this->placed($donor, 'PREGNANT_OR_LACTATING');
        $lifted = $this->placed($donor, 'FEVER_NONSPECIFIC');
        $lifted->forceFill(['lifted_at' => now(), 'lifted_by' => User::factory()->create()->id, 'lift_note' => 'x'])->save();
        $staff = $this->userOf($donor->registered_facility_id);

        $this->getJson("/api/v1/donors/{$donor->public_id}/deferrals?filter[active]=1", $this->headers($staff, withKey: false))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason_code', 'PREGNANT_OR_LACTATING');
    }

    public function test_the_full_history_includes_lifted_rows(): void
    {
        $donor = $this->donor();
        $this->placed($donor, 'PREGNANT_OR_LACTATING');
        $lifted = $this->placed($donor, 'FEVER_NONSPECIFIC');
        $lifted->forceFill(['lifted_at' => now(), 'lifted_by' => User::factory()->create()->id, 'lift_note' => 'x'])->save();

        $this->getJson("/api/v1/donors/{$donor->public_id}/deferrals", $this->headers($this->userOf($donor->registered_facility_id), withKey: false))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_staff_of_another_facility_cannot_read_a_donors_deferrals(): void
    {
        $donor = $this->donor();
        $this->placed($donor, 'PREGNANT_OR_LACTATING');

        $this->getJson("/api/v1/donors/{$donor->public_id}/deferrals", $this->headers($this->userOf(Facility::factory()->create()->id), withKey: false))
            ->assertForbidden();
    }

    public function test_a_donor_role_of_the_same_facility_cannot_read_deferrals(): void
    {
        $donor = $this->donor();
        $this->placed($donor, 'PREGNANT_OR_LACTATING');

        $this->getJson("/api/v1/donors/{$donor->public_id}/deferrals", $this->headers($this->userOf($donor->registered_facility_id, 'donor'), withKey: false))
            ->assertForbidden();
    }

    public function test_a_donor_role_of_the_same_facility_cannot_place_a_deferral(): void
    {
        $donor = $this->donor();

        $this->postJson("/api/v1/donors/{$donor->public_id}/deferrals", [
            'reason_code' => 'TATTOO_PIERCING_ACUPUNCTURE',
            'anchor_at' => '2026-03-01T08:00:00+00:00',
        ], $this->headers($this->userOf($donor->registered_facility_id, 'donor')))->assertForbidden();

        $this->assertSame(0, Deferral::query()->count());
    }

    public function test_staff_of_another_facility_cannot_lift(): void
    {
        $donor = $this->donor();
        $deferral = $this->placed($donor, 'PREGNANT_OR_LACTATING');

        $this->patchJson("/api/v1/deferrals/{$deferral->public_id}/lift", ['reason' => 'x'], $this->headers($this->userOf(Facility::factory()->create()->id)))
            ->assertForbidden();
        $this->assertNull($deferral->fresh()?->lifted_at);
    }

    public function test_deferral_reasons_returns_only_active_master_data(): void
    {
        $seeded = DeferralReason::query()->where('is_active', true)->count();
        DeferralReason::query()->where('code', 'FEVER_NONSPECIFIC')->update(['is_active' => false]);

        $response = $this->getJson('/api/v1/deferral-reasons', $this->headers($this->userOf(Facility::factory()->create()->id), withKey: false));

        $response->assertOk()->assertJsonCount($seeded - 1, 'data');
        $this->assertNotContains('FEVER_NONSPECIFIC', array_column($response->json('data'), 'code'));
    }

    public function test_the_second_page_is_reachable_with_the_cursor_from_the_first_page(): void
    {
        $donor = $this->donor();
        $this->placed($donor, 'PREGNANT_OR_LACTATING');
        $this->placed($donor, 'FEVER_NONSPECIFIC');
        $this->placed($donor, 'TATTOO_PIERCING_ACUPUNCTURE');
        $headers = $this->headers($this->userOf($donor->registered_facility_id), withKey: false);

        $first = $this->getJson("/api/v1/donors/{$donor->public_id}/deferrals?per_page=2", $headers)->assertOk();
        $cursor = $first->json('meta.next_cursor');
        $this->assertIsString($cursor);

        $second = $this->getJson("/api/v1/donors/{$donor->public_id}/deferrals?per_page=2&cursor=".$cursor, $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame([], array_intersect(
            array_column($first->json('data'), 'id'),
            array_column($second->json('data'), 'id'),
        ));
    }
}
