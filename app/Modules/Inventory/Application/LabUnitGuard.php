<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Modules\Inventory\Application\Exceptions\LabResultRejected;
use App\Modules\Inventory\Domain\BatchStatus;

/** Syarat bersama pencatatan hasil lab: unit asal donasi yang masih dalam pengujian. */
final class LabUnitGuard
{
    /** Separated tetap boleh: pemisahan komponen lazim terjadi sebelum hasil lab keluar. */
    private const array TESTABLE = [BatchStatus::QUARANTINED, BatchStatus::TESTING, BatchStatus::SEPARATED];

    public static function assertTestable(BloodBatch $unit): void
    {
        // Hasil lab milik sampel donasi, jadi dicatat di unit asal; turunan
        // membacanya lewat parent_unit_id.
        if ($unit->parent_unit_id !== null) {
            throw LabResultRejected::derivedUnit();
        }

        if (! in_array($unit->status, self::TESTABLE, true)) {
            throw LabResultRejected::notUnderTest($unit->status);
        }
    }
}
