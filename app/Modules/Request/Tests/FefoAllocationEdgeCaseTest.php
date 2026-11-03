<?php

declare(strict_types=1);

namespace App\Modules\Request\Tests;

use App\Models\BloodBatch;
use App\Models\BloodRequest;
use App\Models\BloodRequestAllocation;
use App\Models\Facility;
use App\Models\User;
use App\Modules\Request\Application\AllocateUnitsFefo;
use App\Modules\Request\Application\CreateBloodRequest;
use App\Modules\Request\Domain\RequestUrgency;
use App\Shared\Blood\BloodComponent;
use App\Shared\Blood\BloodGroup;
use App\Shared\Blood\RhFactor;
use Database\Seeders\CompatibilityRuleSeeder;
use Database\Seeders\ComponentTypeSeeder;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pengecualian kedaluwarsa dan status pada kandidat FEFO (kartu 670). Setiap test memeriksa
 * query kandidat DAN alokasinya: yang dijaga adalah kantong yang benar-benar keluar.
 */
final class FefoAllocationEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ComponentTypeSeeder::class);
        $this->seed(CompatibilityRuleSeeder::class);

        $this->freezeSecond();
        $this->facility = Facility::factory()->create();
    }

    /** Kompatibel dan released; test mengubah hanya bagian yang diuji. */
    private function unit(string $expiresAt = '+5 days', string $status = 'released'): BloodBatch
    {
        $unit = BloodBatch::factory()->create(['component' => 'packed_red_cells', 'facility_id' => $this->facility->id]);

        $unit->forceFill([
            'blood_group' => 'O',
            'rh_factor' => 'negative',
            'collected_at' => now()->subDays(30),
            'expires_at' => Carbon::parse($expiresAt),
            'status' => $status,
            'released_at' => in_array($status, ['released', 'reserved', 'issued'], true) ? now()->subDays(29) : null,
        ])->save();

        return $unit;
    }

    private function request(string $neededBy = '+1 day'): BloodRequest
    {
        return app(CreateBloodRequest::class)->handle(
            $this->facility->id,
            User::factory()->create(['facility_id' => $this->facility->id])->id,
            BloodComponent::PACKED_RED_CELLS,
            BloodGroup::O,
            RhFactor::NEGATIVE,
            3,
            RequestUrgency::URGENT,
            new DateTimeImmutable($neededBy),
            'RM-'.Str::random(6),
        );
    }

    /** @return list<string> */
    private function candidateIds(BloodRequest $request): array
    {
        return app(AllocateUnitsFefo::class)->candidatesFor($request, 10)->pluck('public_id')->all();
    }

    private function assertNothingAllocatable(BloodRequest $request): void
    {
        $this->assertSame([], $this->candidateIds($request));
        $this->assertSame([], app(AllocateUnitsFefo::class)->handle($request, User::factory()->create(['facility_id' => $this->facility->id])->id));
        $this->assertSame(0, BloodRequestAllocation::query()->count());
    }

    // Celah 4. Dua hal berbeda: status expired meski tanggalnya belum lewat, dan masih
    // released padahal tanggalnya sudah lewat (penjadwal belum sempat menandainya).
    public function test_an_expired_status_and_a_past_expiry_are_each_excluded(): void
    {
        $this->unit('+5 days', 'expired');
        $this->assertNothingAllocatable($this->request());

        $this->unit('-1 hour');
        $this->assertNothingAllocatable($this->request());
    }

    // Celah 5. Batas >=: kantong yang masih hidup tepat saat dibutuhkan ikut terpilih.
    public function test_a_unit_expiring_exactly_at_needed_by_is_selected(): void
    {
        $at = now()->addDay()->toIso8601String();
        $exact = $this->unit($at);
        $this->unit(now()->addDay()->subSecond()->toIso8601String());

        $request = $this->request($at);

        $this->assertSame([$exact->public_id], $this->candidateIds($request));
    }

    // Celah 8. Tanpa baris alokasi: yang menahan di sini murni penyaring statusnya.
    public function test_reserved_and_issued_units_are_excluded(): void
    {
        foreach (['reserved', 'issued'] as $status) {
            $this->unit('+5 days', $status);
        }

        $this->assertNothingAllocatable($this->request());
    }
}
