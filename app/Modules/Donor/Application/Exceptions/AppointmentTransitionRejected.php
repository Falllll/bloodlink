<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Exceptions;

use App\Modules\Donor\Domain\AppointmentStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class AppointmentTransitionRejected extends DomainException
{
    // Do NOT name this $code: RuntimeException already declares an int $code property.
    private function __construct(private readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function illegal(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self(
            ErrorCode::APPOINTMENT_TRANSITION_REJECTED,
            "Cannot transition an appointment from \"{$from->value}\" to \"{$to->value}\"."
        );
    }

    public static function notYetDue(): self
    {
        return new self(
            ErrorCode::APPOINTMENT_TRANSITION_REJECTED,
            'This appointment cannot be marked no_show before its scheduled time has passed.'
        );
    }

    public static function alreadyOpen(): self
    {
        return new self(
            ErrorCode::APPOINTMENT_TRANSITION_REJECTED,
            'This donor already has an open appointment (booked, arrived, or screened).'
        );
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
