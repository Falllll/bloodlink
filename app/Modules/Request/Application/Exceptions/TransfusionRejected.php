<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class TransfusionRejected extends DomainException
{
    public static function unitNotIssued(string $status): self
    {
        return new self("A unit in status \"{$status}\" cannot have been transfused; only an issued unit can.");
    }

    public static function alreadyRecorded(): self
    {
        return new self('A transfusion is already recorded for this allocation; a unit is transfused once.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::TRANSFUSION_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
