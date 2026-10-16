<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class ReservationRejected extends DomainException
{
    public static function nothingToReserve(): self
    {
        return new self('This blood request has no allocated units left to reserve.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BLOOD_BATCH_TRANSITION_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
