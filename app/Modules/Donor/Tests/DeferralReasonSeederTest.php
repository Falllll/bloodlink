<?php

declare(strict_types=1);

namespace App\Modules\Donor\Tests;

use App\Models\DeferralReason;
use Database\Seeders\DeferralReasonSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DeferralReasonSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_twice_keeps_one_row_per_code(): void
    {
        $this->seed(DeferralReasonSeeder::class);
        $this->seed(DeferralReasonSeeder::class);

        $this->assertSame(23, DeferralReason::query()->count());
    }

    public function test_permanent_reasons_have_no_duration(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $this->assertSame(
            0,
            DeferralReason::query()
                ->where('type', 'permanent')
                ->whereNotNull('default_duration_value')
                ->count()
        );
    }

    public function test_database_rejects_permanent_reason_with_duration(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('deferral_reasons_duration_shape');

        DB::table('deferral_reasons')->insert([
            'jurisdiction' => 'WHO',
            'code' => 'INJECTING_DRUG_USE_TEST',
            'type' => 'permanent',
            'default_duration_value' => 12,
            'default_duration_unit' => 'months',
            'label' => 'Riwayat penggunaan narkoba suntik (uji)',
            'source_reference' => 'Test fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_every_source_reference_cites_the_who_document(): void
    {
        $this->seed(DeferralReasonSeeder::class);

        $rows = DeferralReason::query()->get(['code', 'source_reference']);

        foreach ($rows as $row) {
            $this->assertStringStartsWith('WHO 2012 Blood Donor Selection', $row->source_reference);

            // TTI_CONFIRMED_REACTIVE tidak bersumber dari tabel Technical
            // recommendations -- ia mengutip §4.4/§5 halaman Rujukan Pedoman
            // Medis sendiri (lihat DeferralReasonSeeder), jadi dikecualikan
            // dari pemeriksaan "Technical recommendations" di bawah.
            //
            // LOW_HAEMOGLOBIN, VITAL_SIGNS_OUT_OF_RANGE, dan
            // UNDERWEIGHT_FOR_VOLUME (Kartu 150) juga dikecualikan: ketiganya
            // bersumber dari §3.2/§3.3/§3.4 (badan dokumen) plus §3.6
            // (Turunan, ditambahkan khusus Kartu 150), bukan dari tabel
            // Technical recommendations.
            if (in_array($row->code, [
                'TTI_CONFIRMED_REACTIVE',
                'LOW_HAEMOGLOBIN',
                'VITAL_SIGNS_OUT_OF_RANGE',
                'UNDERWEIGHT_FOR_VOLUME',
            ], true)) {
                continue;
            }

            $this->assertStringContainsString('Technical recommendations', $row->source_reference);
        }
    }

    public function test_a_code_removed_from_the_list_is_deactivated_not_deleted(): void
    {
        DB::table('deferral_reasons')->insert([
            'jurisdiction' => 'WHO',
            'code' => 'OBSOLETE_TEST',
            'type' => 'permanent',
            'label' => 'Kode usang untuk pengujian',
            'source_reference' => 'Test fixture',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->seed(DeferralReasonSeeder::class);

        $row = DeferralReason::query()->where('jurisdiction', 'WHO')->where('code', 'OBSOLETE_TEST')->first();

        $this->assertNotNull($row);
        $this->assertFalse($row->is_active);
    }
}
