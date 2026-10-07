<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TtiTestType;
use App\Modules\Inventory\Domain\TtiTestCode;
use App\Modules\Inventory\Domain\TtiTestRequirement;
use App\Modules\Inventory\Domain\WhoTtiScreeningLimits;
use Illuminate\Database\Seeder;

final class TtiTestTypeSeeder extends Seeder
{
    private const string JURISDICTION = 'WHO';

    private const string SOURCE_MANDATORY = 'WHO Screening Donated Blood for Transfusion-Transmissible Infections (2009); '
        .'Rujukan Pedoman Medis §6.1 (wajib universal), §6.3 (mutu assay)';

    private const string SOURCE_REGIONAL = 'WHO Screening Donated Blood for Transfusion-Transmissible Infections (2009); '
        .'Rujukan Pedoman Medis §6.2 (menurut pola penyakit regional), §6.3 (mutu assay)';

    public function run(): void
    {
        $rows = $this->rows();

        TtiTestType::query()->upsert(
            $rows,
            ['jurisdiction', 'code'],
            ['label', 'requirement', 'preferred_min_sensitivity_percent', 'preferred_min_specificity_percent', 'source_reference', 'is_active']
        );

        // Kode yang hilang dinonaktifkan, tidak dihapus. Menonaktifkan uji wajib
        // sengaja gagal keras di CHECK tti_test_types_quality_and_mandate.
        TtiTestType::query()
            ->where('jurisdiction', self::JURISDICTION)
            ->whereNotIn('code', array_column($rows, 'code'))
            ->update(['is_active' => false]);
    }

    /** @return list<array<string, string|float|bool>> */
    private function rows(): array
    {
        $now = now()->toDateTimeString();

        $row = fn (TtiTestCode $code, string $label, TtiTestRequirement $requirement): array => [
            'jurisdiction' => self::JURISDICTION,
            'code' => $code->value,
            'label' => $label,
            'requirement' => $requirement->value,
            'preferred_min_sensitivity_percent' => WhoTtiScreeningLimits::PREFERRED_MIN_SENSITIVITY_PERCENT,
            'preferred_min_specificity_percent' => WhoTtiScreeningLimits::PREFERRED_MIN_SPECIFICITY_PERCENT,
            'source_reference' => $requirement === TtiTestRequirement::MANDATORY ? self::SOURCE_MANDATORY : self::SOURCE_REGIONAL,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            $row(TtiTestCode::HIV, 'HIV-1 dan HIV-2', TtiTestRequirement::MANDATORY),
            $row(TtiTestCode::HEPATITIS_B, 'Hepatitis B (HBsAg)', TtiTestRequirement::MANDATORY),
            $row(TtiTestCode::HEPATITIS_C, 'Hepatitis C', TtiTestRequirement::MANDATORY),
            $row(TtiTestCode::SYPHILIS, 'Sifilis (antibodi treponemal spesifik)', TtiTestRequirement::MANDATORY),
            $row(TtiTestCode::MALARIA, 'Malaria', TtiTestRequirement::REGIONAL),
            $row(TtiTestCode::CHAGAS, 'Chagas (Trypanosoma cruzi)', TtiTestRequirement::REGIONAL),
            $row(TtiTestCode::HTLV, 'HTLV-I/II', TtiTestRequirement::REGIONAL),
        ];
    }
}
