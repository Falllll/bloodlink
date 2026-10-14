<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Exceptions;

use App\Modules\Request\Domain\RequestStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class BloodRequestNotAllocatable extends DomainException
{
    public static function closed(RequestStatus $status): self
    {
        return new self("A blood request in status \"{$status->value}\" no longer accepts allocations.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BLOOD_REQUEST_NOT_ALLOCATABLE;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
