<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Models\Facility;
use App\Models\FacilityTtiPanelEntry;
use App\Models\TtiTestType;
use App\Modules\Inventory\Application\ResolveTtiPanel;
use App\Modules\Inventory\Domain\TtiPanelVerdict;
use App\Modules\Inventory\Domain\TtiResult;
use App\Modules\Inventory\Domain\TtiTestCode;
use App\Modules\Inventory\Domain\TtiTestRequirement;
use Database\Seeders\TtiTestTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TtiScreeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TtiTestTypeSeeder::class);
    }

    private function type(TtiTestCode $code): TtiTestType
    {
        return TtiTestType::query()->where('jurisdiction', 'WHO')->where('code', $code->value)->firstOrFail();
    }

    public function test_the_seeder_holds_four_mandatory_and_three_regional_tests_idempotently(): void
    {
        $this->seed(TtiTestTypeSeeder::class);

        $this->assertSame(7, TtiTestType::query()->count());
        $this->assertSame(
            [TtiTestCode::HIV, TtiTestCode::HEPATITIS_B, TtiTestCode::HEPATITIS_C, TtiTestCode::SYPHILIS],
            TtiTestType::query()->where('requirement', 'mandatory')->orderBy('id')->get()->map(fn (TtiTestType $t) => $t->code)->all(),
        );
        $this->assertSame(3, TtiTestType::query()->where('requirement', 'regional')->count());

        foreach (TtiTestType::query()->get() as $type) {
            $section = $type->requirement === TtiTestRequirement::MANDATORY ? '§6.1' : '§6.2';
            $this->assertStringContainsString($section, $type->source_reference, $type->code->value);
            $this->assertStringContainsString('§6.3', $type->source_reference, $type->code->value);
        }
    }

    public function test_results_live_in_rows_not_in_per_infection_columns(): void
    {
        foreach (['blood_batches', 'tti_test_results'] as $table) {
            foreach (TtiTestCode::cases() as $code) {
                $this->assertFalse(Schema::hasColumn($table, "{$code->value}_result"), "{$table}.{$code->value}_result");
            }
        }
    }

    public function test_the_panel_is_the_mandatory_tests_plus_regional_opt_ins(): void
    {
        $facility = Facility::factory()->create();
        $panel = fn (): array => (new ResolveTtiPanel)->forFacility($facility->id)->map(fn (TtiTestType $t) => $t->code)->all();

        $this->assertSame([TtiTestCode::HIV, TtiTestCode::HEPATITIS_B, TtiTestCode::HEPATITIS_C, TtiTestCode::SYPHILIS], $panel());

        FacilityTtiPanelEntry::query()->create(['facility_id' => $facility->id, 'tti_test_type_id' => $this->type(TtiTestCode::MALARIA)->id]);

        $this->assertContains(TtiTestCode::MALARIA, $panel());
        $this->assertCount(5, $panel());
        // Opt-in fasilitas lain tidak ikut.
        $this->assertCount(4, (new ResolveTtiPanel)->forFacility(Facility::factory()->create()->id));
    }

    public function test_a_mandatory_test_cannot_be_deactivated(): void
    {
        // Uji regional boleh dimatikan...
        $this->type(TtiTestCode::CHAGAS)->forceFill(['is_active' => false])->save();

        // ...uji wajib tidak.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('tti_test_types_quality_and_mandate');

        DB::update("UPDATE tti_test_types SET is_active = false WHERE code = 'hiv_1_2'");
    }

    public function test_the_database_allows_one_result_per_unit_test_and_stage(): void
    {
        $unit = BloodBatch::factory()->create();
        $row = fn (bool $confirmatory): array => [
            'public_id' => (string) Str::uuid(),
            'blood_batch_id' => $unit->id,
            'tti_test_type_id' => $this->type(TtiTestCode::HIV)->id,
            'is_confirmatory' => $confirmatory,
            'result' => 'reactive',
            'tested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('tti_test_results')->insert($row(false));
        // Uji konfirmasi adalah baris kedua yang sah.
        DB::table('tti_test_results')->insert($row(true));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('tti_test_results_unit_test_stage_unique');

        DB::table('tti_test_results')->insert($row(false));
    }

    public function test_the_verdict_reads_screening_results_only_and_reactive_wins(): void
    {
        $panel = [TtiTestCode::HIV, TtiTestCode::HEPATITIS_B];

        $this->assertSame(TtiPanelVerdict::INCOMPLETE, TtiPanelVerdict::decide($panel, ['hiv_1_2' => TtiResult::NON_REACTIVE]));
        $this->assertSame(TtiPanelVerdict::ALL_NON_REACTIVE, TtiPanelVerdict::decide($panel, [
            'hiv_1_2' => TtiResult::NON_REACTIVE, 'hbsag' => TtiResult::NON_REACTIVE,
        ]));
        // Satu reaktif sudah cukup, meski panel belum lengkap.
        $this->assertSame(TtiPanelVerdict::REACTIVE, TtiPanelVerdict::decide($panel, ['hbsag' => TtiResult::REACTIVE]));
        $this->assertSame(TtiPanelVerdict::REACTIVE, TtiPanelVerdict::decide($panel, [
            'hiv_1_2' => TtiResult::INDETERMINATE, 'hbsag' => TtiResult::REACTIVE,
        ]));
        $this->assertSame(TtiPanelVerdict::INDETERMINATE, TtiPanelVerdict::decide($panel, [
            'hiv_1_2' => TtiResult::NON_REACTIVE, 'hbsag' => TtiResult::INDETERMINATE,
        ]));
    }
}
