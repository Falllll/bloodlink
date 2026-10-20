<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class DonorFlagRejected extends DomainException
{
    public static function alreadyFlagged(): self
    {
        return new self('This donor already has an active administrative flag; lift it before placing another.');
    }

    public static function alreadyLifted(): self
    {
        return new self('This administrative flag has already been lifted.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DONOR_FLAG_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
