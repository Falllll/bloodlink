<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\StorageLocation;
use App\Models\User;
use App\Modules\Inventory\Application\RecordTemperatureReading;
use App\Modules\Inventory\Application\RequestStockTransfer;
use App\Modules\Inventory\Domain\ReadingSource;
use App\Modules\Inventory\Infrastructure\InventoryReportQuery;
use App\Shared\Auth\FacilityScope;
use App\Shared\Reporting\ReportGranularity;
use App\Shared\Reporting\ReportWindow;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InspectsQueries;
use Tests\TestCase;

/**
 * Anggaran query dan rencana query modul Inventory (kartu 445).
 *
 * Endpoint daftar: jumlah query untuk 2 baris harus sama dengan untuk 8 baris. Test ini
 * MENCEGAH, bukan memperbaiki: begitu toApiArray() membaca relasi baru tanpa with()
 * yang sepadan, satu query per baris muncul dan test ini merah.
 *
 * Satu user terautentikasi per test: guard Sanctum memegang user dari request pertama.
 */
final class QueryBudgetTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
    }

    /** Nama role sengaja literal: ModInventory tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    /** @return array<string, string> */
    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    /** Jumlah query satu GET setelah $seed menambah $rows baris. */
    private function listCost(string $uri, User $user, int $rows, callable $seed): int
    {
        foreach (range(1, $rows) as $_) {
            $seed();
        }

        return $this->getCost($uri, $this->bearer($user));
    }

    private function releasedUnitAt(Facility $facility): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['facility_id' => $facility->id]);
        $unit->forceFill(['status' => 'released', 'released_at' => now()])->save();

        return $unit;
    }

    /**
     * Riwayat event realistis: 10 fasilitas x 2.000 event selama ~2 tahun. Di tabel
     * kosong kedua index event berbiaya sama dan planner memilih salah satunya secara
     * acak -- assertion index tidak berarti apa-apa sampai statistiknya mirip produksi.
     * ANALYZE sah di dalam transaksi RefreshDatabase.
     */
    private function seedEventHistory(): void
    {
        foreach (range(1, 10) as $_) {
            BloodBatch::factory()->create(['facility_id' => Facility::factory()->create()->id]);
        }

        DB::statement(<<<'SQL'
            INSERT INTO blood_batch_events (blood_batch_id, batch_public_id, facility_id, from_status, to_status, occurred_at)
            SELECT b.id, b.public_id, b.facility_id,
                   (ARRAY['testing', 'released', 'reserved', 'released', 'released', 'quarantined'])[(g % 6) + 1],
                   (ARRAY['released', 'reserved', 'issued', 'expired', 'discarded', 'testing'])[(g % 6) + 1],
                   TIMESTAMP '2024-01-01' + g * INTERVAL '50 minutes'
            FROM blood_batches b CROSS JOIN generate_series(1, 2000) AS g
        SQL);
        DB::statement('ANALYZE blood_batch_events');
    }

    private function window(string $from, string $to): ReportWindow
    {
        $utc = new DateTimeZone('UTC');

        return ReportWindow::between(new DateTimeImmutable($from, $utc), new DateTimeImmutable($to, $utc), ReportGranularity::MONTH, 366)
            ?? $this->fail('Jendela test melebihi batas bucket.');
    }

    public function test_listing_blood_batches_costs_the_same_for_two_or_eight_component_units(): void
    {
        $facility = Facility::factory()->create();
        $user = $this->userOf($facility->id);
        // Unit komponen: tiap baris membawa facility, donor, storageProfile, DAN parent --
        // relasi terakhir hanya terbaca kalau parent_unit_id terisi.
        $seed = function () use ($facility): void {
            $parent = BloodBatch::factory()->create(['facility_id' => $facility->id, 'component' => 'whole_blood']);
            BloodBatch::factory()->create([
                'facility_id' => $facility->id,
                'parent_unit_id' => $parent->id,
                'donor_id' => null,
                'component' => 'packed_red_cells',
            ]);
        };

        $few = $this->listCost('/api/v1/blood-batches', $user, 1, $seed);
        $many = $this->listCost('/api/v1/blood-batches', $user, 3, $seed);

        $this->assertSame($few, $many);
    }

    public function test_listing_stock_transfers_costs_the_same_for_two_or_eight_rows(): void
    {
        $owner = Facility::factory()->create();
        $user = $this->userOf($owner->id);
        $seed = fn () => app(RequestStockTransfer::class)->handle(
            $this->releasedUnitAt($owner),
            Facility::factory()->create()->id,
            User::factory()->create()->id,
            null,
        );

        $few = $this->listCost('/api/v1/stock-transfers', $user, 2, $seed);
        $many = $this->listCost('/api/v1/stock-transfers', $user, 6, $seed);

        $this->assertSame($few, $many);
    }

    public function test_listing_temperature_readings_costs_the_same_for_two_or_eight_rows(): void
    {
        $facility = Facility::factory()->create();
        $user = $this->userOf($facility->id);
        $location = new StorageLocation([
            'code' => 'FRIDGE-01',
            'label' => 'Lemari PRC 1',
            'kind' => 'refrigerator',
            'purpose' => 'release_ready',
            'temp_min_celsius' => '2.0',
            'temp_max_celsius' => '6.0',
        ]);
        $location->forceFill(['public_id' => (string) Str::uuid()])->assignFacility($facility->id)->save();
        $uri = "/api/v1/storage-locations/{$location->public_id}/temperature-readings";
        $seed = fn () => app(RecordTemperatureReading::class)->handle($location, '4.0', ReadingSource::MANUAL);

        $few = $this->listCost($uri, $user, 2, $seed);
        $many = $this->listCost($uri, $user, 6, $seed);

        $this->assertSame($few, $many);
    }

    public function test_the_facility_wastage_report_uses_the_facility_event_index(): void
    {
        $this->seedEventHistory();
        $user = $this->userOf((int) DB::table('blood_batch_events')->value('facility_id'), 'admin');

        $plan = $this->queryPlan(app(InventoryReportQuery::class)->wastageQuery($this->window('2025-01-01', '2025-01-31'), $user));

        $this->assertStringContainsString('blood_batch_events_facility_id_to_status_occurred_at_index', $plan);
    }

    public function test_the_global_wastage_and_stock_trend_reports_use_the_status_time_index(): void
    {
        $this->seedEventHistory();
        $operator = $this->userOf(null, 'admin');
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::GLOBAL_SCOPE);
        $this->assertTrue($operator->isGlobalOperator());
        $window = $this->window('2025-01-01', '2025-01-31');
        $reports = app(InventoryReportQuery::class);

        // Stock trend tidak menyaring status secara logika; tanpa whereIn(to_status, semua
        // status) yang "redundan" itu, jalur ini memindai seluruh riwayat event. Dengan
        // seqscan dimatikan planner tetap bisa memakai index ini lewat full index scan
        // TANPA kondisi to_status, jadi yang di-assert adalah Index Cond-nya, bukan
        // sekadar ketiadaan Seq Scan.
        foreach ([$reports->wastageQuery($window, $operator), $reports->stockFlowQuery($window, $operator)] as $query) {
            $plan = $this->queryPlan($query);

            $this->assertStringContainsString('blood_batch_events_to_status_occurred_at_index', $plan);
            $this->assertStringContainsString('Index Cond: (((to_status)::text = ANY', $plan);
        }
    }
}
