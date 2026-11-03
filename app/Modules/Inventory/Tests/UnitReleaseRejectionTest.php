<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\TtiTestType;
use App\Models\User;
use App\Modules\Inventory\Application\RecordTtiTestResult;
use App\Modules\Inventory\Application\ReleaseUnit;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TtiPanelVerdict;
use App\Modules\Inventory\Domain\TtiResult;
use App\Modules\Inventory\Domain\TtiTestCode;
use App\Shared\Auth\FacilityScope;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TtiTestTypeSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Gerbang rilis dari sisi yang tidak tertutup UnitReleaseGateTest (kartu 660): status yang
 * tidak boleh rilis, dan penyaring is_confirmatory di verdictFor(). Satu user terautentikasi
 * per test; hasil lab dicatat lewat service.
 */
final class UnitReleaseRejectionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<TtiTestCode> */
    private const array MANDATORY = [TtiTestCode::HIV, TtiTestCode::HEPATITIS_B, TtiTestCode::HEPATITIS_C, TtiTestCode::SYPHILIS];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(TtiTestTypeSeeder::class);
    }

    /** @return array<string, string> */
    private function headersFor(BloodBatch $unit): array
    {
        $user = User::factory()->create(['facility_id' => $unit->facility_id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId(FacilityScope::of($unit->facility_id));
        $user->assignRole('hospital_staff');
        app(PermissionRegistrar::class)->setPermissionsTeamId(-1);

        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken];
    }

    private function quarantinedUnit(?int $facilityId = null): BloodBatch
    {
        return BloodBatch::factory()->create([
            'component' => 'whole_blood',
            'status' => 'quarantined',
            ...($facilityId !== null ? ['facility_id' => $facilityId] : []),
        ]);
    }

    private function record(BloodBatch $unit, TtiTestCode $code, TtiResult $result, bool $confirmatory = false): void
    {
        app(RecordTtiTestResult::class)->handle($unit, $code, $result, $confirmatory, new DateTimeImmutable('2026-03-02T09:00:00+00:00'), null);
    }

    /** Panel bersih, supaya yang menolak benar-benar graf status, bukan vonis TTI. */
    private function clearedUnitIn(BatchStatus $status, ?int $facilityId = null): BloodBatch
    {
        $unit = $this->quarantinedUnit($facilityId);

        foreach (self::MANDATORY as $code) {
            $this->record($unit, $code, TtiResult::NON_REACTIVE);
        }

        $unit->forceFill([
            'status' => $status,
            'released_at' => in_array($status, [BatchStatus::RESERVED, BatchStatus::ISSUED], true) ? new DateTimeImmutable('2026-03-03T09:00:00+00:00') : null,
            'discard_reason' => $status === BatchStatus::DISCARDED ? 'kartu 660' : null,
        ])->save();

        return $unit;
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function postRelease(BloodBatch $unit, array $headers): TestResponse
    {
        return $this->postJson("/api/v1/blood-batches/{$unit->public_id}/release", [], $headers + ['Idempotency-Key' => (string) Str::uuid()]);
    }

    // Celah 4. Bentuk penolakan persis, dari tiap status yang graf-nya menolak rilis.
    public function test_a_terminal_status_is_rejected_with_the_exact_409_shape(): void
    {
        $first = $this->clearedUnitIn(BatchStatus::ISSUED);
        $headers = $this->headersFor($first);

        foreach ([BatchStatus::ISSUED, BatchStatus::EXPIRED, BatchStatus::DISCARDED] as $status) {
            $unit = $status === BatchStatus::ISSUED ? $first : $this->clearedUnitIn($status, $first->facility_id);
            $releasedAt = $unit->fresh()?->released_at;

            $this->postRelease($unit, $headers)
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED')
                ->assertJsonPath('error.message', "A blood batch cannot be released from \"{$status->value}\".");

            $fresh = $unit->fresh();
            $this->assertSame($status, $fresh?->status);
            $this->assertEquals($releasedAt, $fresh->released_at);
        }
    }

    /**
     * Celah 4, status reserved. CACAT YANG DIKETAHUI, dipaku supaya terlihat.
     * YANG BENAR: 409 notReleasableFrom("reserved"). Hari ini RESERVED -> RELEASED sah di graf
     * (jalur pelepasan reservasi), jadi walkToReleased meloloskannya: unit pesanan kembali
     * "released" dan released_at-nya tertimpa, tanpa melepas alokasinya. Saat cacatnya
     * diperbaiki di kartunya sendiri, test ini merah dan harus dibalik.
     */
    public function test_known_defect_the_gate_releases_a_reserved_unit(): void
    {
        $unit = $this->clearedUnitIn(BatchStatus::RESERVED);
        $before = $unit->fresh()?->released_at;
        $this->travel(1)->hours();

        $this->postRelease($unit, $this->headersFor($unit))->assertOk();

        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::RELEASED, $fresh?->status);
        $this->assertNotEquals($before, $fresh->released_at);
    }

    /**
     * Kabel yang tidak dijaga test mana pun sebelum ini: tanpa where('is_confirmatory', false),
     * konfirmasi non-reaktif menimpa skrining di peta vonis. Test lama tetap hijau karena unit
     * reaktif sudah discarded -- yang bocor sungguhan adalah skrining INDETERMINATE, yang
     * tidak memusnahkan unit.
     */
    public function test_a_confirmatory_result_never_clears_an_indeterminate_screen(): void
    {
        $unit = $this->quarantinedUnit();
        foreach ([TtiTestCode::HIV, TtiTestCode::HEPATITIS_B, TtiTestCode::HEPATITIS_C] as $code) {
            $this->record($unit, $code, TtiResult::NON_REACTIVE);
        }
        $this->record($unit, TtiTestCode::SYPHILIS, TtiResult::INDETERMINATE);
        $this->record($unit, TtiTestCode::SYPHILIS, TtiResult::NON_REACTIVE, confirmatory: true);

        $this->assertSame(TtiPanelVerdict::INDETERMINATE, app(ReleaseUnit::class)->verdictFor($unit));

        $this->postRelease($unit, $this->headersFor($unit))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVENTORY_RELEASE_REJECTED');

        $fresh = $unit->fresh();
        $this->assertSame(BatchStatus::QUARANTINED, $fresh?->status);
        $this->assertNull($fresh->released_at);
    }

    public function test_a_confirmatory_result_never_changes_a_reactive_verdict(): void
    {
        $unit = $this->quarantinedUnit();
        $this->record($unit, TtiTestCode::SYPHILIS, TtiResult::REACTIVE);
        $this->record($unit, TtiTestCode::SYPHILIS, TtiResult::NON_REACTIVE, confirmatory: true);

        $this->assertSame(TtiPanelVerdict::REACTIVE, app(ReleaseUnit::class)->verdictFor($unit));
    }

    /**
     * Celah 3 sampai ke gerbang. CACAT YANG DIKETAHUI, dipaku supaya terlihat.
     * YANG BENAR: 409. Fasilitas tidak bisa mencapai ini (uji wajib selalu ikut panel), dan
     * CHECK tti_test_types_quality_and_mandate melarang uji wajib dinonaktifkan. Yang tersisa:
     * baris master datanya tidak ada, mis. TtiTestTypeSeeder belum dijalankan di lingkungan
     * baru -- unit tanpa hasil apa pun lolos rilis.
     */
    public function test_known_defect_a_missing_test_master_releases_an_untested_unit(): void
    {
        TtiTestType::query()->delete();
        $unit = $this->quarantinedUnit();

        $this->postRelease($unit, $this->headersFor($unit))->assertOk();

        $this->assertSame(BatchStatus::RELEASED, $unit->fresh()?->status);
        $this->assertSame(0, $unit->ttiTestResults()->count());
    }
}
