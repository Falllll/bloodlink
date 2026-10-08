<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class DonorAccountNotLinked extends DomainException
{
    public static function forUser(int $userId): self
    {
        return new self("User account {$userId} is not linked to an active donor record.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::DONOR_ACCOUNT_NOT_LINKED;
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
