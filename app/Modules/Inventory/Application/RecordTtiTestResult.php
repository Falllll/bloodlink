<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\TtiTestResult;
use App\Models\TtiTestType;
use App\Modules\Inventory\Application\Exceptions\LabResultRejected;
use App\Modules\Inventory\Domain\TtiResult;
use App\Modules\Inventory\Domain\TtiTestCode;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mencatat satu hasil uji IMLTD. TIDAK PERNAH menyentuh status unit: hasil
 * non-reaktif tidak merilis apa pun (Kartu 240), hasil reaktif tidak
 * memusnahkan apa pun (Kartu 250).
 */
final class RecordTtiTestResult
{
    public function __construct(private ResolveTtiPanel $panel) {}

    public function handle(
        BloodBatch $unit,
        TtiTestCode $code,
        TtiResult $result,
        bool $confirmatory,
        DateTimeImmutable $testedAt,
        ?int $recordedBy,
    ): TtiTestResult {
        LabUnitGuard::assertTestable($unit);

        $testType = $this->panel->forFacility($unit->facility_id)
            ->first(fn (TtiTestType $type): bool => $type->code === $code);

        if ($testType === null) {
            throw LabResultRejected::notInPanel($code);
        }

        if ($confirmatory && ! $this->hasNonNegativeScreen($unit, $testType)) {
            throw LabResultRejected::confirmatoryWithoutScreen();
        }

        $row = new TtiTestResult([
            'tti_test_type_id' => $testType->id,
            'is_confirmatory' => $confirmatory,
            'result' => $result,
            'tested_at' => $testedAt,
        ]);

        $row->forceFill([
            'public_id' => (string) Str::uuid(),
            'blood_batch_id' => $unit->id,
            // Rantai look-back §6.3: donasi -> unit -> hasil.
            'donation_id' => $unit->donation_id,
            'recorded_by' => $recordedBy,
        ]);

        try {
            // Savepoint: pelanggaran unique tidak boleh membatalkan transaksi luar.
            DB::transaction(fn (): bool => $row->save());
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'tti_test_results_unit_test_stage_unique')) {
                throw LabResultRejected::duplicate();
            }

            throw $e;
        }

        return $row;
    }

    private function hasNonNegativeScreen(BloodBatch $unit, TtiTestType $testType): bool
    {
        return TtiTestResult::query()
            ->where('blood_batch_id', $unit->id)
            ->where('tti_test_type_id', $testType->id)
            ->where('is_confirmatory', false)
            ->whereIn('result', [TtiResult::REACTIVE->value, TtiResult::INDETERMINATE->value])
            ->exists();
    }
}
