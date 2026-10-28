<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\DeferralReason;
use App\Models\Donor;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Donor\Application\FlagDonor;
use App\Modules\Donor\Application\PlaceDeferral;
use App\Modules\Donor\Domain\DeferralSource;
use App\Modules\Donor\Domain\DonorFlagReason;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\DeferralReasonSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GET /me/donor/eligibility (kartu 537): kelayakan berbasis RIWAYAT. Inti kartu ada di
 * test deferral dan flag -- dua hal yang POST /eligibility/self-check secara struktural
 * tidak bisa lihat. Satu user terautentikasi per test: guard Sanctum memegang user dari
 * request pertama meski tokennya berganti; donor lain dibuktikan lewat database.
 */
final class MeEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private const string URI = '/api/v1/me/donor/eligibility';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeferralReasonSeeder::class);

        $this->freezeSecond();
    }

    /** Nama role sengaja literal: ModDonor tidak boleh bergantung ke DomIdentity. */
    private function user(string $role, ?Facility $facility = null): User
    {
        $user = User::factory()->create(['facility_id' => $facility?->id]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facility?->id));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    /**
     * Donor dewasa, cukup berat, donasi terakhir setahun lalu (jarak donor terpenuhi),
     * tertaut ke akun $user. Penautan user_id dikerjakan staf; di sini ditulis langsung.
     */
    private function linkedEligibleDonor(?User $user = null): Donor
    {
        $donor = Donor::factory()->create(['registered_facility_id' => Facility::factory()->create()->id]);
        $donor->forceFill([
            'user_id' => $user?->id,
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'last_donation_date' => now()->subYear()->toDateString(),
        ])->save();

        return $donor;
    }

    private function deferMedically(Donor $donor): void
    {
        app(PlaceDeferral::class)->handle(
            $donor,
            DeferralReason::query()->where('code', 'TATTOO_PIERCING_ACUPUNCTURE')->firstOrFail(),
            now()->toDateTimeImmutable(),
            DeferralSource::MANUAL,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function findingCodes(array $payload): array
    {
        $findings = $payload['findings'];
        $this->assertIsArray($findings);

        return array_values(array_map(fn (mixed $f): string => is_array($f) ? (string) $f['code'] : '', $findings));
    }

    public function test_it_returns_eligible_for_a_donor_with_no_deferral_and_a_met_interval(): void
    {
        $user = $this->user('donor');
        $this->linkedEligibleDonor($user);

        $this->getJson(self::URI, $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.outcome', 'eligible')
            ->assertJsonPath('data.retry_after_date', null)
            ->assertJsonPath('data.findings', []);
    }

    public function test_an_active_medical_deferral_appears_as_a_finding(): void
    {
        $user = $this->user('donor');
        $donor = $this->linkedEligibleDonor($user);
        $this->deferMedically($donor);

        $history = $this->getJson(self::URI, $this->bearer($user))->assertOk()->json('data');

        $this->assertContains('ACTIVE_DEFERRAL', $this->findingCodes($history));
        $this->assertNotSame('eligible', $history['outcome']);

        // Kontrasnya: kuesioner anonim dengan data donor yang SAMA tetap "eligible",
        // karena self-check tidak punya akses ke deferral. Inilah alasan kartu ini ada.
        $selfCheck = $this->postJson('/api/v1/eligibility/self-check', [
            'date_of_birth' => $donor->date_of_birth->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
            'last_donation_date' => $donor->last_donation_date?->toDateString(),
        ])->assertOk()->json('data');

        $this->assertSame('eligible', $selfCheck['outcome']);
        $this->assertNotContains('ACTIVE_DEFERRAL', $this->findingCodes($selfCheck));
    }

    public function test_an_active_administrative_flag_appears_as_a_finding(): void
    {
        $user = $this->user('donor');
        $donor = $this->linkedEligibleDonor($user);
        app(FlagDonor::class)->place($donor, DonorFlagReason::REPEATED_NO_SHOW, 'Tiga kali tidak hadir.', null, $this->user('admin'));

        $history = $this->getJson(self::URI, $this->bearer($user))->assertOk()->json('data');

        $this->assertContains('ACTIVE_ADMIN_FLAG', $this->findingCodes($history));
        $this->assertNotSame('eligible', $history['outcome']);
    }

    public function test_it_reports_is_provisional_false(): void
    {
        $user = $this->user('donor');
        $this->linkedEligibleDonor($user);

        $history = $this->getJson(self::URI, $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.is_provisional', false)
            ->json('data');

        // Bentuk sisanya identik dengan self-check: klien cukup punya satu parser.
        $selfCheck = $this->postJson('/api/v1/eligibility/self-check', [
            'date_of_birth' => now()->subYears(30)->toDateString(),
            'sex' => 'male',
            'weight_kg' => 70,
        ])->assertOk()->json('data');

        $this->assertTrue($selfCheck['is_provisional']);
        $this->assertSame(array_keys($selfCheck), array_keys($history));
    }

    public function test_finding_entries_have_the_self_check_shape(): void
    {
        $user = $this->user('donor');
        $this->deferMedically($this->linkedEligibleDonor($user));

        $this->getJson(self::URI, $this->bearer($user))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'outcome', 'is_provisional', 'retry_after_date',
                'findings' => [['code', 'outcome', 'retry_after_date', 'detail']],
            ]]);
    }

    public function test_a_user_without_a_linked_donor_row_gets_404_donor_account_not_linked(): void
    {
        // Akun petugas: tidak ada baris donors yang tertaut.
        $staff = $this->user('hospital_staff', Facility::factory()->create());

        $this->getJson(self::URI, $this->bearer($staff))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'DONOR_ACCOUNT_NOT_LINKED');
    }

    public function test_it_does_not_leak_another_donors_eligibility(): void
    {
        $user = $this->user('donor');
        $mine = $this->linkedEligibleDonor($user);
        // Donor lain, tertaut ke akun lain, dengan deferral aktif.
        $other = $this->linkedEligibleDonor($this->user('donor'));
        $this->deferMedically($other);

        // Kedua baris benar-benar ada di DB, dengan kelayakan berbeda.
        $this->assertSame(0, DB::table('deferrals')->where('donor_id', $mine->id)->count());
        $this->assertSame(1, DB::table('deferrals')->where('donor_id', $other->id)->count());

        $this->getJson(self::URI, $this->bearer($user))
            ->assertOk()
            ->assertJsonPath('data.outcome', 'eligible')
            ->assertJsonPath('data.findings', []);
    }

    public function test_the_answer_never_reaches_the_log(): void
    {
        // Data kesehatan: tidak ada outcome, kode finding, maupun tanggal lahir di log.
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $user = $this->user('donor');
        $donor = $this->linkedEligibleDonor($user);
        $this->deferMedically($donor);

        $this->getJson(self::URI, $this->bearer($user))->assertOk();

        $all = implode("\n", $logged);
        foreach (['ACTIVE_DEFERRAL', 'not_eligible', 'outcome', $donor->date_of_birth->toDateString()] as $needle) {
            $this->assertStringNotContainsString($needle, $all);
        }
    }

    public function test_the_endpoint_writes_nothing(): void
    {
        $user = $this->user('donor');
        $this->linkedEligibleDonor($user);
        $auditBefore = DB::table('audit_logs')->count();

        $this->getJson(self::URI, $this->bearer($user))->assertOk();

        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
    }
}
