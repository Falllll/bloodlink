<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Listeners;

use App\Models\BloodBatch;
use App\Models\Donation;
use App\Models\Facility;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use App\Modules\Inventory\Application\GenerateUnitNumber;
use App\Modules\Inventory\Domain\BatchStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

// Sengaja sinkron, BUKAN ShouldQueue: RecordDonation memanggil event() di dalam
// DB::transaction-nya, jadi kalau karantina gagal, donasinya ikut batal.
final class CreateQuarantinedUnit
{
    public function __construct(private GenerateUnitNumber $unitNumbers) {}

    public function handle(DonationCompleted $event): void
    {
        // Tanpa visibleTo(): tidak ada user di sini. Baris utuh, tanpa select kolom.
        $donation = Donation::query()->findOrFail($event->donationId);
        $facilityCode = (string) Facility::query()->whereKey($donation->facility_id)->value('code');

        try {
            // retrying() menyimpan di dalam savepoint, jadi pelanggaran unique tidak
            // membatalkan transaksi RecordDonation di luar sana.
            $this->unitNumbers->retrying(
                $facilityCode,
                $donation->completed_at,
                fn (string $number): bool => $this->unitFor($donation, $number)->save(),
            );
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'blood_batches_donation_id_unique')) {
                throw $e;
            }

            // Replay event yang sama: unitnya sudah ada, tidak ada yang perlu dibuat.
        }
    }

    private function unitFor(Donation $donation, string $unitNumber): BloodBatch
    {
        $unit = new BloodBatch;

        $unit->assignFacility($donation->facility_id);

        // expires_at TIDAK diisi -- itu Kartu 200. Golongan darah boleh null
        // sampai dikonfirmasi lab (Kartu 230).
        return $unit->forceFill([
            'public_id' => (string) Str::uuid(),
            'batch_number' => $unitNumber,
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
}
