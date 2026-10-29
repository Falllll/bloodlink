<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Donor;
use App\Models\DonorCampaign;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\RequestUrgency;
use App\Modules\Request\Infrastructure\CrossmatchTransfusionReportQuery;
use App\Shared\Auth\FacilityScope;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
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
 * Anggaran query dan rencana query modul Request (kartu 445). Satu user
 * terautentikasi per test.
 */
final class QueryBudgetTest extends TestCase
{
    use InspectsQueries;
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->facility = Facility::factory()->create();
    }

    /** Nama role sengaja literal: ModRequest tidak boleh bergantung ke DomIdentity. */
    private function userOf(?int $facilityId, string $role = 'hospital_staff'): User
    {
        $user = User::factory()->create(['facility_id' => $facilityId]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($facilityId));
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return $user;
    }

    private function request(): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            2,
            RequestUrgency::URGENT,
            new DateTimeImmutable('+1 day'),
            'RM-'.Str::random(6),
        );
    }

    public function test_listing_blood_requests_costs_the_same_for_two_or_eight_rows(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->userOf($this->facility->id)->createToken('api')->plainTextToken];

        foreach (range(1, 2) as $_) {
            $this->request();
        }
        $few = $this->getCost('/api/v1/blood-requests', $headers);

        foreach (range(1, 6) as $_) {
            $this->request();
        }
        $many = $this->getCost('/api/v1/blood-requests', $headers);

        $this->assertSame($few, $many);
    }

    /** Satu permintaan terbuka dengan kampanye aktif dan dua kontak di dua tahap. */
    private function requestWithActiveCampaign(): void
    {
        $campaignId = DB::table('donor_campaigns')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'blood_request_id' => $this->request()->id,
            'started_by' => User::factory()->create()->id,
            'current_radius_meters' => 15000,
            'stage_entered_at' => now(),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([[5000, 'accepted'], [15000, null]] as [$radius, $response]) {
            DB::table('donor_campaign_contacts')->insert([
                'public_id' => (string) Str::uuid(),
                'donor_campaign_id' => $campaignId,
                'donor_id' => Donor::factory()->create()->id,
                'radius_meters' => $radius,
                'distance_meters' => $radius - 1000,
                'contacted_at' => now(),
                'responded_at' => $response === null ? null : now(),
                'response' => $response,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Penjaga keputusan "satu query agregat" (kartu 500): loop yang memanggil agregat
     * per permintaan lolos review dengan tenang dan hanya ketahuan di sini.
     */
    public function test_the_facility_dashboard_costs_the_same_for_one_or_five_open_requests(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->userOf($this->facility->id)->createToken('api')->plainTextToken];

        $this->requestWithActiveCampaign();
        $one = $this->getCost('/api/v1/dashboard/campaigns', $headers);

        foreach (range(1, 4) as $_) {
            $this->requestWithActiveCampaign();
        }
        $five = $this->getCost('/api/v1/dashboard/campaigns', $headers);

        $this->assertSame($one, $five);
        $this->assertCount(5, $this->getJson('/api/v1/dashboard/campaigns', $headers)->json('data'));
    }

    /**
     * Riwayat alokasi satu kantong -- bentuk yang sama dengan pemeriksaan FK
     * restrictOnDelete. Unique index parsial (WHERE released_at IS NULL) tidak bisa
     * melayaninya: baris yang sudah dilepas tidak ada di dalamnya.
     */
    public function test_the_allocation_history_of_a_unit_uses_the_full_fk_index(): void
    {
        $plan = $this->queryPlan(BloodRequestAllocation::query()->where('blood_batch_id', 1));

        $this->assertStringContainsString('blood_request_allocations_blood_batch_id_index', $plan);
        $this->assertStringNotContainsString('Seq Scan', $plan);
    }

    /** Query DonorCampaignController::show(): kampanye terbaru, aktif ATAU selesai. */
    public function test_the_latest_campaign_of_a_request_uses_the_full_fk_index(): void
    {
        $plan = $this->queryPlan(DonorCampaign::query()
            ->where('blood_request_id', 1)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(1));

        $this->assertStringContainsString('donor_campaigns_blood_request_id_index', $plan);
        $this->assertStringNotContainsString('Seq Scan', $plan);
    }

    /**
     * COUNT di BloodRequestController::candidates() sengaja round-trip terpisah dan
     * murah (lihat AGENTS.md): pada 200k baris ia Index Only Scan atas
     * (blood_request_id, released_at).
     *
     * Yang di-assert: filter permintaan dilayani Index Cond. "Tanpa Seq Scan" saja tidak
     * cukup -- dengan seqscan dimatikan planner memindai index parsial dan memfilter
     * blood_request_id di atasnya, dan itu tetap lolos. Butuh statistik realistis.
     */
    /**
     * 2.000 kantong, masing-masing untuk satu permintaan dengan tiga alokasi: dua sudah
     * dilepas, satu aktif. Proporsi aktif WAJIB realistis: kalau semua alokasi sudah
     * dilepas, index parsial "satu aktif per kantong" kosong, nyaris gratis dipindai,
     * dan pilihan planner jadi untung-untungan (test ini pernah flaky karenanya).
     */
    private function seedAllocationHistory(): void
    {
        $actor = User::factory()->create()->id;
        BloodBatch::factory()->create(['facility_id' => $this->facility->id]);

        DB::statement(<<<'SQL'
            INSERT INTO blood_batches (public_id, batch_number, donor_id, facility_id, component, blood_group, rh_factor,
                                       volume_ml, status, collected_at, expires_at, storage_profile_id, created_at, updated_at)
            SELECT gen_random_uuid(), 'QB-' || g, b.donor_id, b.facility_id, b.component, b.blood_group, b.rh_factor,
                   b.volume_ml, b.status, b.collected_at, b.expires_at, b.storage_profile_id, now(), now()
            FROM blood_batches b CROSS JOIN generate_series(1, 2000) AS g
        SQL);
        DB::statement(<<<'SQL'
            INSERT INTO blood_requests (public_id, facility_id, patient_reference, component, blood_group, rh_factor,
                                        quantity, urgency, status, needed_by, requested_by, created_at, updated_at)
            SELECT gen_random_uuid(), b.facility_id, b.batch_number, 'packed_red_cells', 'O', 'positive',
                   1, 'routine', 'open', now(), ?, now(), now()
            FROM blood_batches b WHERE b.batch_number LIKE 'QB-%'
        SQL, [$actor]);
        DB::statement(<<<'SQL'
            INSERT INTO blood_request_allocations (public_id, blood_request_id, blood_batch_id, allocated_by, allocated_at,
                                                   released_at, release_reason, created_at, updated_at)
            SELECT gen_random_uuid(), r.id, b.id, ?, now(),
                   CASE WHEN n = 3 THEN NULL ELSE now() END,
                   CASE WHEN n = 3 THEN NULL ELSE 'dilepas' END, now(), now()
            FROM blood_requests r
            JOIN blood_batches b ON b.batch_number = r.patient_reference
            CROSS JOIN generate_series(1, 3) AS n
        SQL, [$actor]);

        DB::statement('ANALYZE blood_request_allocations');
    }

    public function test_the_remaining_count_for_candidates_is_served_by_the_request_index(): void
    {
        $this->seedAllocationHistory();

        $plan = $this->queryPlan(BloodRequestAllocation::query()
            ->where('blood_request_id', (int) DB::table('blood_requests')->value('id'))
            ->whereNull('released_at')
            ->toBase()
            ->selectRaw('COUNT(*)'));

        $this->assertStringContainsString('blood_request_allocations_blood_request_id_released_at_index', $plan);
        $this->assertMatchesRegularExpression('/Index Cond: \(+blood_request_id = /', $plan);
    }

    /**
     * Riwayat realistis: 10 fasilitas x 300 permintaan, masing-masing satu alokasi
     * (sudah dilepas), satu crossmatch, satu transfusi, tersebar Januari-Maret 2024. Di tabel
     * kosong urutan join dipilih acak dan assertion index tidak berarti apa-apa.
     */
    private function seedTransfusionHistory(): void
    {
        $actor = User::factory()->create()->id;
        foreach (range(1, 10) as $_) {
            $facility = Facility::factory()->create();
            BloodBatch::factory()->create(['facility_id' => $facility->id]);
        }

        DB::statement(<<<'SQL'
            INSERT INTO blood_requests (public_id, facility_id, patient_reference, component, blood_group, rh_factor,
                                        quantity, urgency, status, needed_by, requested_by, created_at, updated_at)
            SELECT gen_random_uuid(), b.facility_id, 'RM-' || g, 'packed_red_cells', 'O', 'positive',
                   1, 'routine', 'fulfilled', TIMESTAMP '2024-01-01' + g * INTERVAL '6 hours', ?, now(), now()
            FROM blood_batches b CROSS JOIN generate_series(1, 300) AS g
        SQL, [$actor]);
        DB::statement(<<<'SQL'
            INSERT INTO blood_request_allocations (public_id, blood_request_id, blood_batch_id, allocated_by, allocated_at,
                                                   released_at, release_reason, created_at, updated_at)
            SELECT gen_random_uuid(), r.id, b.id, ?, r.needed_by, r.needed_by, 'selesai', now(), now()
            FROM blood_requests r JOIN blood_batches b ON b.facility_id = r.facility_id
        SQL, [$actor]);
        DB::statement(<<<'SQL'
            INSERT INTO crossmatches (public_id, blood_request_allocation_id, performed_by, result, performed_at, created_at, updated_at)
            SELECT gen_random_uuid(), a.id, ?, 'compatible', a.allocated_at, now(), now() FROM blood_request_allocations a
        SQL, [$actor]);
        DB::statement(<<<'SQL'
            INSERT INTO transfusions (public_id, blood_request_allocation_id, recorded_by, started_at, created_at, updated_at)
            SELECT gen_random_uuid(), a.id, ?, a.allocated_at, now(), now() FROM blood_request_allocations a
        SQL, [$actor]);

        foreach (['blood_requests', 'blood_request_allocations', 'crossmatches', 'transfusions'] as $table) {
            DB::statement("ANALYZE {$table}");
        }
    }

    /**
     * Jalur fasilitas laporan C:T (kartu 440) masuk lewat index fasilitas blood_requests,
     * lalu turun ke allocations -> crossmatches/transfusions. Jalur operator global
     * SENGAJA tidak dijaga: crossmatches tanpa index performed_at memindai tabel, dan
     * laporan global yang jarang dibuka tidak membenarkan biaya tulis index baru.
     *
     * Yang di-assert index MASUKNYA, bukan sekadar ketiadaan Seq Scan: dengan seqscan
     * dimatikan, jalur global pun lolos lewat full index scan tanpa kondisi fasilitas.
     */
    public function test_the_facility_crossmatch_transfusion_report_enters_through_the_facility_index(): void
    {
        $this->seedTransfusionHistory();
        $user = $this->userOf((int) DB::table('blood_requests')->value('facility_id'), 'admin');
        $utc = new DateTimeZone('UTC');
        $window = ReportWindow::between(new DateTimeImmutable('2024-02-01', $utc), new DateTimeImmutable('2024-02-29', $utc), ReportGranularity::MONTH, 366)
            ?? $this->fail('Jendela test melebihi batas bucket.');
        $report = app(CrossmatchTransfusionReportQuery::class);

        foreach (['crossmatches' => 'performed_at', 'transfusions' => 'started_at'] as $table => $column) {
            $plan = $this->queryPlan($report->countPerBucket($table, $column, $window, $user));

            $this->assertStringContainsString('blood_requests_facility_id_status_index', $plan);
            $this->assertStringNotContainsString('Seq Scan', $plan);
        }
    }
}
