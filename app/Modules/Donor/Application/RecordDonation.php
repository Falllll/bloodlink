<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Appointment;
use App\Models\Donation;
use App\Models\Donor;
use App\Modules\Donor\Application\Exceptions\AppointmentTransitionRejected;
use App\Modules\Donor\Domain\AppointmentStatus;
use App\Modules\Donor\Domain\Events\DonationCompleted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RecordDonation
{
    public function __construct(private TransitionAppointment $transition) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Appointment $appointment, array $attributes, ?int $collectedBy = null): Donation
    {
        // Rujukan §5: skrining dulu, baru pengambilan. Tanpa ini ada kantong
        // tanpa pemeriksaan di belakangnya.
        if ($appointment->status !== AppointmentStatus::SCREENED) {
            throw AppointmentTransitionRejected::illegal($appointment->status, AppointmentStatus::COMPLETED);
        }

        try {
            return DB::transaction(function () use ($appointment, $attributes, $collectedBy): Donation {
                $donation = new Donation([
                    'volume_ml' => (int) $attributes['volume_ml'],
                    'started_at' => $attributes['started_at'],
                    'completed_at' => $attributes['completed_at'],
                    'note' => $attributes['note'] ?? null,
                ]);

                $screeningId = $appointment->screenings()->latest('screened_at')->latest('id')->value('id');

                $donation->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'donor_id' => $appointment->donor_id,
                    'facility_id' => $appointment->facility_id,
                    'appointment_id' => $appointment->id,
                    'screening_id' => $screeningId,
                    'collected_by' => $collectedBy,
                ])->save();

                $this->transition->handle($appointment, AppointmentStatus::COMPLETED);

                /** @var Donor $donor */
                $donor = $appointment->donor;

                // forceFill, BUKAN fill: kedua kolom ini tidak ada di Donor::$fillable,
                // dan fill() akan mengabaikannya diam-diam -- donasi tersimpan tapi
                // DonationIntervalRule tetap menganggap donor belum pernah menyumbang.
                $donor->forceFill([
                    'last_donation_date' => $donation->completed_at->toDateString(),
                    'donation_count' => $donor->donation_count + 1,
                ])->save();

                // Sengaja DI DALAM transaksi: CreateQuarantinedUnit sinkron, jadi kalau
                // ia gagal seluruh donasi ikut batal -- darah yang tidak bisa dikarantina
                // tidak boleh tercatat sudah diambil. Kalau pendengar itu kelak dibuat
                // ShouldQueue, dispatch-nya WAJIB pindah ke ->afterCommit(), atau job
                // akan jalan sebelum baris donasinya ada.
                event(new DonationCompleted($donor->id, $donation->id));

                return $donation;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dua request berbeda untuk janji temu yang sama yang lolos cek status
            // bersamaan: unique index appointment_id yang menahan yang kedua.
            if (str_contains($e->getMessage(), 'donations_appointment_id_unique')) {
                throw AppointmentTransitionRejected::donationAlreadyRecorded();
            }

            throw $e;
        }
    }
}
