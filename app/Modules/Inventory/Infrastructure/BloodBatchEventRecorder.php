<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure;

use App\Models\BloodBatch;
use App\Models\BloodBatchEvent;
use App\Models\User;
use App\Modules\Inventory\Domain\BatchStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Dipasang di batas MODEL, bukan di dalam service transisi: status unit berubah
 * lewat TransitionBloodBatch, ReleaseUnit, SeparateIntoComponents, dan
 * ApplyReactiveTtiConsequences. Event Eloquent adalah satu-satunya titik lewat
 * yang dilalui keempatnya.
 *
 * Batas yang dinyatakan terbuka: DB::table('blood_batches')->update() melewati
 * Eloquent dan TIDAK terekam.
 */
final class BloodBatchEventRecorder
{
    public function created(BloodBatch $batch): void
    {
        $this->record($batch, null, $this->birthStatus($batch));
    }

    public function updated(BloodBatch $batch): void
    {
        // Perubahan kolom lain BUKAN peristiwa status.
        if (! array_key_exists('status', $batch->getChanges())) {
            return;
        }

        // getRawOriginal, bukan getOriginal: yang terakhir sudah di-cast ke enum.
        $this->record($batch, BatchStatus::from((string) $batch->getRawOriginal('status')), $batch->status);
    }

    private function record(BloodBatch $batch, ?BatchStatus $from, BatchStatus $to): void
    {
        // Aktor diambil persis seperti AuditObserver mengambilnya.
        /** @var User|null $actor */
        $actor = Auth::user();

        BloodBatchEvent::query()->create([
            'blood_batch_id' => $batch->id,
            'batch_public_id' => $batch->public_id,
            'facility_id' => $batch->facility_id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $to === BatchStatus::DISCARDED ? $batch->discard_reason : null,
            'actor_id' => $actor?->id,
            'actor_facility_id' => $actor?->facility_id,
            'trace_id' => Context::get('trace_id'),
            'occurred_at' => now(),
        ]);
    }

    /**
     * Baris yang dibuat tanpa status menerima default kolom dari database; model
     * belum memegang nilainya, jadi dibaca dari baris yang sudah tersimpan.
     *
     * Nilainya juga ditanam ke model sebagai original: tanpa itu, update
     * berikutnya pada instance yang sama membaca getRawOriginal('status') = null
     * dan updated() tidak bisa menentukan status asalnya.
     */
    private function birthStatus(BloodBatch $batch): BatchStatus
    {
        if (array_key_exists('status', $batch->getAttributes())) {
            return $batch->status;
        }

        $raw = (string) BloodBatch::query()->whereKey($batch->getKey())->toBase()->value('status');

        $batch->setRawAttributes([...$batch->getAttributes(), 'status' => $raw]);
        $batch->syncOriginalAttribute('status');

        return BatchStatus::from($raw);
    }
}
