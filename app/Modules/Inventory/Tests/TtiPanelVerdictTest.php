<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Tests;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\LabResultRejected;
use App\Modules\Inventory\Application\LabUnitGuard;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TtiPanelVerdict;
use App\Modules\Inventory\Domain\TtiResult;
use App\Modules\Inventory\Domain\TtiTestCode;
use Tests\TestCase;

/**
 * Vonis panel TTI sebagai unit murni (kartu 660): TANPA database. Kalau logika vonisnya
 * salah, yang merah ada di sini, bukan di test HTTP yang jauh dari penyebabnya.
 */
final class TtiPanelVerdictTest extends TestCase
{
    /** @var list<TtiTestCode> */
    private const array PANEL = [TtiTestCode::HIV, TtiTestCode::HEPATITIS_B];

    // Celah 1. Hasil di luar panel: reaktif tidak pernah diabaikan, non-reaktif tidak menambal.
    public function test_a_result_outside_the_panel_never_gets_ignored_or_fills_a_gap(): void
    {
        $clean = [TtiTestCode::HIV->value => TtiResult::NON_REACTIVE, TtiTestCode::HEPATITIS_B->value => TtiResult::NON_REACTIVE];
        $outside = TtiTestCode::MALARIA->value;

        $this->assertSame(TtiPanelVerdict::ALL_NON_REACTIVE, TtiPanelVerdict::decide(self::PANEL, $clean));
        $this->assertSame(TtiPanelVerdict::REACTIVE, TtiPanelVerdict::decide(self::PANEL, [...$clean, $outside => TtiResult::REACTIVE]));
        $this->assertSame(TtiPanelVerdict::INDETERMINATE, TtiPanelVerdict::decide(self::PANEL, [...$clean, $outside => TtiResult::INDETERMINATE]));
        $this->assertSame(TtiPanelVerdict::ALL_NON_REACTIVE, TtiPanelVerdict::decide(self::PANEL, [...$clean, $outside => TtiResult::NON_REACTIVE]));
        $this->assertSame(TtiPanelVerdict::INCOMPLETE, TtiPanelVerdict::decide(self::PANEL, [
            TtiTestCode::HIV->value => TtiResult::NON_REACTIVE,
            $outside => TtiResult::NON_REACTIVE,
        ]));
    }

    // Celah 2. Hasil meragukan yang sudah tercatat lebih penting daripada uji yang masih kurang.
    public function test_indeterminate_outranks_incomplete(): void
    {
        $this->assertSame(TtiPanelVerdict::INDETERMINATE, TtiPanelVerdict::decide(self::PANEL, [
            TtiTestCode::HIV->value => TtiResult::INDETERMINATE,
        ]));
    }

    // Celah 3. Panel kosong tidak pernah berarti bersih: tidak ada yang terbukti diuji.
    public function test_an_empty_panel_is_incomplete(): void
    {
        $this->assertSame(TtiPanelVerdict::INCOMPLETE, TtiPanelVerdict::decide([], []));
        // Hasil non-reaktif di luar panel tidak menambal panel yang kosong.
        $this->assertSame(TtiPanelVerdict::INCOMPLETE, TtiPanelVerdict::decide([], [TtiTestCode::HIV->value => TtiResult::NON_REACTIVE]));
        // Reaktif tetap menang atas panel kosong.
        $this->assertSame(TtiPanelVerdict::REACTIVE, TtiPanelVerdict::decide([], [TtiTestCode::HIV->value => TtiResult::REACTIVE]));
    }

    // Celah 5. Skrining reaktif memusnahkan unit, padahal konfirmasi baru dicatat sesudahnya.
    public function test_only_tti_results_may_be_recorded_on_a_discarded_unit(): void
    {
        $unit = (new BloodBatch)->forceFill(['status' => BatchStatus::DISCARDED, 'parent_unit_id' => null]);

        LabUnitGuard::assertTtiRecordable($unit);
        $this->addToAssertionCount(1);

        $this->expectException(LabResultRejected::class);
        $this->expectExceptionMessage('Lab results cannot be recorded on a unit in status "discarded".');

        LabUnitGuard::assertTestable($unit);
    }
}
