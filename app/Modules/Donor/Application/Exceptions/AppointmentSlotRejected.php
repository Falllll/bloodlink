<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

/** Waktu yang diminta bukan slot yang bisa dipesan di fasilitas ini (kartu 503). */
final class AppointmentSlotRejected extends DomainException
{
    private function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notASlot(): self
    {
        return new self('The requested time is not the start of an appointment slot at this facility. Pick a slot from the availability endpoint.', 422);
    }

    public static function closed(): self
    {
        return new self('The facility is closed for appointments on this date.', 422);
    }

    /** 409: slotnya sah, tapi sudah penuh -- terisi orang lain lebih dulu. */
    public static function full(): self
    {
        return new self('This appointment slot is already full. Pick another slot.', 409);
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::APPOINTMENT_SLOT_UNAVAILABLE;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }
}
