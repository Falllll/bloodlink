<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\User;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Laporan wastage dan arus stok (kartu 440). Satu user terautentikasi per test:
 * guard Sanctum memegang user dari request pertama meski tokennya berganti.
 *
 * Semua peristiwa ditanam di 2025, jauh dari peristiwa lahir unit (now()) yang
 * ditulis BloodBatchEventRecorder saat factory membuat unit.
 */
final class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'admin'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return TestResponse<JsonResponse> */
    private function report(User $user, string $report, string $query): TestResponse
    {
        return $this->getJson("/api/v1/reports/{$report}?{$query}", [
            'Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken,
        ]);
    }

    private function unitAt(Facility $facility): BloodBatch
    {
        return BloodBatch::factory()->create(['facility_id' => $facility->id, 'status' => 'quarantined']);
    }

    /** Langsung ke tabel event: append-only hanya menolak UPDATE/DELETE, bukan INSERT. */
    private function event(BloodBatch $unit, ?string $from, string $to, string $at): void
    {
        DB::table('blood_batch_events')->insert([
            'blood_batch_id' => $unit->id,
            'batch_public_id' => $unit->public_id,
            'facility_id' => $unit->facility_id,
            'from_status' => $from,
            'to_status' => $to,
            'occurred_at' => $at,
        ]);
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, array<string, mixed>>
     */
    private function byPeriod(TestResponse $response): array
    {
        return array_column($response->json('data'), null, 'period_start');
    }

    public function test_a_unit_released_then_expired_counts_in_two_different_buckets(): void
    {
        $facility = Facility::factory()->create();
        $unit = $this->unitAt($facility);
        $this->event($unit, 'testing', 'released', '2025-01-15 10:00:00');
        $this->event($unit, 'released', 'expired', '2025-02-10 10:00:00');

        $response = $this->report($this->userOf($facility->id), 'wastage', 'from=2025-01-01&to=2025-02-28&granularity=month');

        $response->assertOk();
        $rows = $this->byPeriod($response);
        $this->assertSame(['2025-01-01', '2025-02-01'], array_keys($rows));

        $this->assertSame(['expired' => 0, 'released' => 1, 'numerator' => 0, 'denominator' => 1], array_intersect_key($rows['2025-01-01'], array_flip(['released', 'expired', 'numerator', 'denominator'])));
        $this->assertSame(0.0, $rows['2025-01-01']['ratio']);

        $this->assertSame(['expired' => 1, 'released' => 0, 'numerator' => 1, 'denominator' => 0], array_intersect_key($rows['2025-02-01'], array_flip(['released', 'expired', 'numerator', 'denominator'])));
        // Penyebut 0: tidak terdefinisi, bukan nol.
        $this->assertNull($rows['2025-02-01']['ratio']);
    }

    public function test_the_ratio_carries_its_numerator_and_denominator(): void
    {
        $facility = Facility::factory()->create();
        foreach (range(1, 4) as $i) {
            $this->event($this->unitAt($facility), 'testing', 'released', "2025-03-0{$i} 08:00:00");
        }
        $this->event($this->unitAt($facility), 'quarantined', 'discarded', '2025-03-20 08:00:00');

        $response = $this->report($this->userOf($facility->id), 'wastage', 'from=2025-03-01&to=2025-03-31&granularity=month');

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertSame(1, $row['discarded']);
        $this->assertSame(1, $row['numerator']);
        $this->assertSame(4, $row['denominator']);
        $this->assertSame(0.25, $row['ratio']);
        $response->assertJsonPath('meta.numerator', 'transitions to discarded or expired');
    }

    public function test_a_cancelled_reservation_does_not_inflate_the_denominator(): void
    {
        $facility = Facility::factory()->create();
        $unit = $this->unitAt($facility);
        $this->event($unit, 'testing', 'released', '2025-04-01 08:00:00');
        $this->event($unit, 'released', 'reserved', '2025-04-02 08:00:00');
        $this->event($unit, 'reserved', 'released', '2025-04-03 08:00:00');

        $response = $this->report($this->userOf($facility->id), 'wastage', 'from=2025-04-01&to=2025-04-30&granularity=month');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.0.denominator'));
    }

    public function test_the_window_is_aligned_to_full_iso_weeks_and_empty_buckets_are_zero(): void
    {
        $facility = Facility::factory()->create();
        // 2025-01-05 Minggu, 2025-01-06 Senin: dua minggu ISO berbeda.
        $this->event($this->unitAt($facility), 'released', 'expired', '2025-01-05 23:59:59');
        $this->event($this->unitAt($facility), 'released', 'expired', '2025-01-06 00:00:00');

        $response = $this->report($this->userOf($facility->id), 'wastage', 'from=2025-01-02&to=2025-01-21&granularity=week');

        $response->assertOk();
        $response->assertJsonPath('meta.from', '2024-12-30');
        $response->assertJsonPath('meta.to_exclusive', '2025-01-27');
        $this->assertSame(
            ['2024-12-30' => 1, '2025-01-06' => 1, '2025-01-13' => 0, '2025-01-20' => 0],
            array_map(fn (array $r): int => $r['expired'], $this->byPeriod($response)),
        );
    }

    public function test_a_facility_admin_counts_only_its_own_facility(): void
    {
        $facilityA = Facility::factory()->create();
        $facilityB = Facility::factory()->create();
        $this->event($this->unitAt($facilityA), 'released', 'expired', '2025-05-05 08:00:00');
        // Baris B harus benar-benar ada; tanpa itu test ini hijau meski scope-nya dicabut.
        $this->event($this->unitAt($facilityB), 'released', 'expired', '2025-05-06 08:00:00');
        $this->event($this->unitAt($facilityB), 'released', 'discarded', '2025-05-07 08:00:00');

        $response = $this->report($this->userOf($facilityA->id), 'wastage', 'from=2025-05-01&to=2025-05-31&granularity=month');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.0.numerator'));
    }

    public function test_a_global_admin_counts_every_facility(): void
    {
        $this->event($this->unitAt(Facility::factory()->create()), 'released', 'expired', '2025-05-05 08:00:00');
        $this->event($this->unitAt(Facility::factory()->create()), 'released', 'discarded', '2025-05-07 08:00:00');

        $response = $this->report($this->userOf(null), 'wastage', 'from=2025-05-01&to=2025-05-31&granularity=month');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.0.numerator'));
    }

    public function test_hospital_staff_without_report_view_gets_403(): void
    {
        $facility = Facility::factory()->create();

        $this->report($this->userOf($facility->id, 'hospital_staff'), 'wastage', 'from=2025-01-01&to=2025-01-31&granularity=month')
            ->assertForbidden();
    }

    public function test_an_injected_granularity_is_422_and_never_reaches_sql(): void
    {
        $facility = Facility::factory()->create();

        $response = $this->report($this->userOf($facility->id), 'wastage', "from=2025-01-01&to=2025-01-31&granularity=day'%3B+DROP+TABLE+audit_logs%3B+--");

        $response->assertUnprocessable();
        $response->assertJsonPath('error.details.granularity.0.rule', 'enum');
        $this->assertTrue(Schema::hasTable('audit_logs'));
    }

    public function test_too_many_buckets_is_422_but_a_full_daily_year_is_allowed(): void
    {
        $facility = Facility::factory()->create();
        $user = $this->userOf($facility->id);

        $this->report($user, 'wastage', 'from=2024-01-01&to=2024-12-31&granularity=day')
            ->assertOk()
            ->assertJsonCount(366, 'data');

        $this->report($user, 'wastage', 'from=2000-01-01&to=2025-12-31&granularity=day')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.to.0.rule', 'too_many_buckets')
            // Bernama, bukan posisional: klien menyebut batasnya di pesan.
            ->assertJsonPath('error.details.to.0.params', ['max' => '366']);
    }

    public function test_dates_must_be_plain_dates_in_order_and_unknown_keys_are_rejected(): void
    {
        $facility = Facility::factory()->create();
        $user = $this->userOf($facility->id);

        $this->report($user, 'wastage', 'from=2025-02-01&to=2025-01-01&granularity=month')
            ->assertUnprocessable()->assertJsonPath('error.details.to.0.rule', 'after_or_equal');
        $this->report($user, 'wastage', 'from=2025-01-01T00:00:00Z&to=2025-01-31&granularity=month')
            ->assertUnprocessable()->assertJsonPath('error.details.from.0.rule', 'date_format');
        $this->report($user, 'wastage', 'from=2025-01-01&to=2025-01-31&granularity=month&facility_id=1')
            ->assertUnprocessable()->assertJsonPath('error.details.facility_id.0.rule', 'not_allowed');
    }

    public function test_stock_trend_counts_transitions_into_each_status_per_bucket(): void
    {
        $facility = Facility::factory()->create();
        $unit = $this->unitAt($facility);
        $this->event($unit, 'testing', 'released', '2025-06-02 08:00:00');
        $this->event($unit, 'released', 'reserved', '2025-06-03 08:00:00');
        $this->event($unit, 'reserved', 'issued', '2025-06-10 08:00:00');
        $this->event($this->unitAt($facility), 'testing', 'released', '2025-06-03 08:00:00');

        $response = $this->report($this->userOf($facility->id), 'stock-trend', 'from=2025-06-02&to=2025-06-15&granularity=week');

        $response->assertOk();
        $rows = $this->byPeriod($response);
        $this->assertSame(['2025-06-02', '2025-06-09'], array_keys($rows));
        $this->assertSame(2, $rows['2025-06-02']['transitions_to']['released']);
        $this->assertSame(1, $rows['2025-06-02']['transitions_to']['reserved']);
        $this->assertSame(0, $rows['2025-06-02']['transitions_to']['issued']);
        $this->assertSame(1, $rows['2025-06-09']['transitions_to']['issued']);
        $this->assertSame(
            ['quarantined', 'testing', 'released', 'reserved', 'issued', 'discarded', 'expired', 'separated'],
            array_keys($rows['2025-06-09']['transitions_to']),
        );
    }
}
