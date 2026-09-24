<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application;

use App\Models\Appointment;
use App\Modules\Donor\Application\Exceptions\AppointmentTransitionRejected;
use App\Modules\Donor\Domain\AppointmentStatus;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class TransitionAppointment
{
    public function handle(
        Appointment $appointment,
        AppointmentStatus $to,
        ?DateTimeImmutable $at = null,
    ): Appointment {
        $from = $appointment->status;

        if (! $from->canTransitionTo($to)) {
            throw AppointmentTransitionRejected::illegal($from, $to);
        }

        if ($to === AppointmentStatus::NO_SHOW
            && $appointment->scheduled_for !== null
            && $appointment->scheduled_for->isFuture()) {
            throw AppointmentTransitionRejected::notYetDue();
        }

        $timestamp = $at ?? now();

        DB::transaction(function () use ($appointment, $to, $timestamp): void {
            $appointment->forceFill([
                'status' => $to,
                $this->timestampColumnFor($to) => $timestamp,
            ])->save();
        });

        return $appointment;
    }

    private function timestampColumnFor(AppointmentStatus $status): string
    {
        return match ($status) {
            AppointmentStatus::ARRIVED => 'arrived_at',
            AppointmentStatus::SCREENED => 'screened_at',
            AppointmentStatus::COMPLETED => 'completed_at',
            AppointmentStatus::NO_SHOW => 'no_show_at',
            AppointmentStatus::CANCELLED => 'cancelled_at',
            AppointmentStatus::BOOKED => throw new LogicException('BOOKED is never a transition target.'),
        };
    }
}
