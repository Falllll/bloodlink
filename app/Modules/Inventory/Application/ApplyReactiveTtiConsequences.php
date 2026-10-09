<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Models\BloodBatch;
use App\Models\TtiTestResult;
use App\Modules\Donor\Domain\Events\DonorPermanentlyDeferred;
use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\ReactiveConsequence;
use App\Modules\Inventory\Domain\TtiTestCode;
use DateTimeInterface;

/**
 * Akibat satu hasil IMLTD reaktif. Dipanggil RecordTtiTestResult DI DALAM
 * transaksi yang menyimpan baris hasilnya.
 */
final class ApplyReactiveTtiConsequences
{
    /** Kode alasan deferral permanen, sudah di-seed DeferralReasonSeeder (jurisdiction WHO). */
    private const string REASON_CODE = 'TTI_CONFIRMED_REACTIVE';

    public function __construct(private TransitionBloodBatch $transition) {}

    public function handle(BloodBatch $unit, TtiTestResult $row, TtiTestCode $code): void
    {
        match (ReactiveConsequence::decide($row->result, $row->is_confirmatory)) {
            ReactiveConsequence::DISCARD_UNIT => $this->discardCascade($unit, $code),
            ReactiveConsequence::DEFER_DONOR_PERMANENTLY => $this->emitPermanentDeferral($unit, $row),
            ReactiveConsequence::NONE => null,
        };
    }

    /** Induk + seluruh turunannya (§11.1: "and all blood components derived from it"). */
    private function discardCascade(BloodBatch $unit, TtiTestCode $code): void
    {
        // Saringan graf, bukan try/catch: sudah discarded (idempoten), induk
        // separated (terminal), dan anak issued (urusan look-back) dilewati.
        foreach ([$unit, ...$unit->children()->get()] as $target) {
            if (! $target->status->canTransitionTo(BatchStatus::DISCARDED)) {
                continue;
            }

            $this->transition->handle($target, BatchStatus::DISCARDED, self::discardReason($code));
        }
    }

    private function emitPermanentDeferral(BloodBatch $unit, TtiTestResult $row): void
    {
        // Unit pindahan antar bank darah tidak punya donor; payload event bertipe int.
        if ($unit->donor_id === null) {
            return;
        }

        event(new DonorPermanentlyDeferred(
            (int) $unit->donor_id,
            self::REASON_CODE,
            $row->tested_at->toDateTimeImmutable()->format(DateTimeInterface::ATOM),
        ));
    }

    private static function discardReason(TtiTestCode $code): string
    {
        return "TTI screening reactive: {$code->value}";
    }
}
