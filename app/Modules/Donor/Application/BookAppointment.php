<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Appointment;
use App\Models\Donor;
use App\Modules\Donor\Application\Exceptions\AppointmentTransitionRejected;
use App\Modules\Donor\Application\Exceptions\DonorIdentityConflict;
use App\Modules\Donor\Domain\AppointmentStatus;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class BookAppointment
{
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

        $status = $scheduledFor === null ? AppointmentStatus::ARRIVED : AppointmentStatus::BOOKED;

        try {
            return DB::transaction(function () use ($donor, $scheduledFor, $createdBy, $note, $status): Appointment {
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
