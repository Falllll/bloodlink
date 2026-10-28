<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Appointment;
use App\Models\Donor;
use App\Models\Facility;
use App\Modules\Donor\Application\Exceptions\AppointmentTransitionRejected;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use App\Modules\Donor\Domain\AppointmentStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class BookAppointment
{
    public function __construct(private AppointmentSlots $slots) {}

    public function handle(
        Donor $donor,
        ?DateTimeImmutable $scheduledFor,
        ?int $createdBy = null,
        ?string $note = null,
    ): Appointment {
        if ($donor->merged_into_id !== null) {
            throw DonorIdentityConflict::mergeConflict(
                "This donor record has been merged into {$donor->mergedInto?->public_id}."
            );
        }

        // Instan UTC, ditulis eksplisit (kartu 503): kolom scheduled_for tanpa zona dan
        // zona aplikasi UTC. Tanpa konversi ini, "09:00+07:00" tersimpan sebagai 09:00 UTC
        // -- tujuh jam meleset, tanpa satu pun error.
        $scheduledFor = $scheduledFor?->setTimezone(new DateTimeZone('UTC'));

        $status = $scheduledFor === null ? AppointmentStatus::ARRIVED : AppointmentStatus::BOOKED;

        try {
            return DB::transaction(function () use ($donor, $scheduledFor, $createdBy, $note, $status): Appointment {
                // Walk-in (null) tidak pernah memakan slot. Pemesanan terjadwal dicocokkan ke
                // pola fasilitas; kunci baris jendela dipegang sampai transaksi ini commit.
                if ($scheduledFor !== null) {
                    $this->slots->assertBookable(
                        Facility::query()->findOrFail($donor->registered_facility_id),
                        $scheduledFor,
                    );
                }

                $appointment = new Appointment([
                    'scheduled_for' => $scheduledFor,
                    'note' => $note,
                ]);

                $serverAttributes = [
                    'public_id' => (string) Str::uuid(),
                    'donor_id' => $donor->id,
                    'facility_id' => $donor->registered_facility_id,
                    'status' => $status,
                    'created_by' => $createdBy,
                ];

                if ($status === AppointmentStatus::ARRIVED) {
                    $serverAttributes['arrived_at'] = now();
                }

                $appointment->forceFill($serverAttributes)->save();

                return $appointment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'appointments_one_open_per_donor')) {
                throw AppointmentTransitionRejected::alreadyOpen();
            }

            throw $e;
        }
    }
}
