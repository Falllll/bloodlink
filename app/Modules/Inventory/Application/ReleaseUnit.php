<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\TtiTestResult;
use App\Models\TtiTestType;
use App\Modules\Inventory\Application\Exceptions\UnitReleaseRejected;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TtiPanelVerdict;
use App\Modules\Inventory\Domain\TtiTestCode;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gerbang rilis (Kartu 240): satu-satunya tempat di repo yang boleh menulis
 * BatchStatus::RELEASED. TransitionBloodBatch tetap menolak RELEASED.
 */
final class ReleaseUnit
{
    public function __construct(private ResolveTtiPanel $panel) {}

    public function handle(BloodBatch $unit): BloodBatch
    {
        // Komponen turunan tidak punya hasil TTI sendiri (LabUnitGuard menolaknya):
        // vonis selalu dibaca dari unit asal donasi.
        $source = $unit->parent ?? $unit;

        $verdict = $this->verdictFor($source);

        if ($verdict !== TtiPanelVerdict::ALL_NON_REACTIVE) {
            throw UnitReleaseRejected::screeningNotCleared($verdict);
        }

        DB::transaction(function () use ($unit): void {
            // Baca ulang dengan kunci: dua rilis bersamaan tidak boleh sama-sama
            // lolos cek graf dari model basi (preseden TransitionBloodBatch).
            $fresh = BloodBatch::query()
                ->whereKey($unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->walkToReleased($fresh);

            $fresh->forceFill(['released_at' => new DateTimeImmutable])->save();
        });

        return $unit->refresh();
    }

    public function verdictFor(BloodBatch $source): TtiPanelVerdict
    {
        $panel = $this->panel->forFacility($source->facility_id);

        // HANYA hasil skrining. Peta di bawah dikunci kode uji, jadi baris konfirmasi
        // non-reaktif akan menimpa skrining reaktif pada kode yang sama -- kantong
        // reaktif lolos tanpa error. Penyaringnya di query, bukan di koleksi.
        $results = $source->ttiTestResults()->with('testType')->where('is_confirmatory', false)->get();

        $codes = $panel->map(fn (TtiTestType $type): TtiTestCode => $type->code)->values()->all();
        $screening = [];
        foreach ($results as $result) {
            /** @var TtiTestResult $result */
            $screening[$result->testType->code->value] = $result->result;
        }

        return TtiPanelVerdict::decide($codes, $screening);
    }

    /**
     * Graf tidak punya QUARANTINED -> RELEASED; kartu 230 tidak pernah memindahkan
     * unit ke TESTING, jadi gerbang menempuh QUARANTINED -> TESTING -> RELEASED
     * dalam satu transaksi, setiap langkah diperiksa graf. Enum tidak dilonggarkan.
     */
    private function walkToReleased(BloodBatch $fresh): void
    {
        $from = $fresh->status;

        if ($fresh->status === BatchStatus::QUARANTINED) {
            if (! $fresh->status->canTransitionTo(BatchStatus::TESTING)) {
                throw UnitReleaseRejected::notReleasableFrom($from);
            }

            $fresh->status = BatchStatus::TESTING;
        }

        if (! $fresh->status->canTransitionTo(BatchStatus::RELEASED)) {
            throw UnitReleaseRejected::notReleasableFrom($from);
        }

        $fresh->status = BatchStatus::RELEASED;
    }
}
