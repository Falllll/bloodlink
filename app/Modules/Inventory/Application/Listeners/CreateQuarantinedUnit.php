<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Models\BloodBatch;
use App\Models\Donation;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Inventory\Domain\BatchStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Sengaja sinkron, BUKAN ShouldQueue: RecordDonation memanggil event() di dalam
// DB::transaction-nya, jadi kalau karantina gagal, donasinya ikut batal.
final class CreateQuarantinedUnit
{
    /** Kedua index ini hanya bisa ditabrak oleh donasi yang sama: nomor batch diturunkan dari donation_id. */
    private const array REPLAY_CONSTRAINTS = [
        'blood_batches_donation_id_unique',
        'blood_batches_batch_number_unique',
    ];

    public function handle(DonationCompleted $event): void
    {
        // Tanpa visibleTo(): tidak ada user di sini. Baris utuh, tanpa select kolom.
        $donation = Donation::query()->findOrFail($event->donationId);

        try {
            // Savepoint: di PostgreSQL unique violation membatalkan transaksi yang
            // sedang berjalan, termasuk transaksi RecordDonation di luar sana.
            DB::transaction(fn () => $this->unitFor($donation)->save());
        } catch (UniqueConstraintViolationException $e) {
            if (! Str::contains($e->getMessage(), self::REPLAY_CONSTRAINTS)) {
                throw $e;
            }

            // Replay event yang sama: unitnya sudah ada, tidak ada yang perlu dibuat.
        }
    }

    private function unitFor(Donation $donation): BloodBatch
    {
        $unit = new BloodBatch;

        $unit->assignFacility($donation->facility_id);

        // expires_at TIDAK diisi -- itu Kartu 200. Golongan darah boleh null
        // sampai dikonfirmasi lab (Kartu 230).
        return $unit->forceFill([
            'public_id' => (string) Str::uuid(),
            'batch_number' => $this->placeholderBatchNumber($donation->id),
            'donation_id' => $donation->id,
            'donor_id' => $donation->donor_id,
            'component' => 'whole_blood',
            'blood_group' => $donation->donor->blood_group,
            'rh_factor' => $donation->donor->rh_factor,
            'volume_ml' => $donation->volume_ml,
            'collected_at' => $donation->completed_at,
            'status' => BatchStatus::QUARANTINED,
        ]);
    }

    private function placeholderBatchNumber(int $donationId): string
    {
        // Penampung sementara sampai Kartu 210; deterministik supaya replay
        // menabrak unique index alih-alih membuat nomor baru.
        return 'DON-'.str_pad((string) $donationId, 12, '0', STR_PAD_LEFT);
    }
}
