<?php

declare(strict_types=1);

namespace App\Modules\Request\Application\Exceptions;

use App\Modules\Request\Domain\CrossmatchResult;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class CrossmatchRejected extends DomainException
{
    public static function allocationIsReleased(): self
    {
        return new self('This allocation has been released; there is nothing to crossmatch or issue.');
    }

    public static function noCrossmatchOnRecord(): self
    {
        return new self('A unit cannot be issued without a crossmatch on record.');
    }

    public static function latestResultForbidsIssue(CrossmatchResult $result): self
    {
        return new self("The latest crossmatch result is \"{$result->value}\"; only a compatible result permits issue.");
    }

    public static function unitNotReserved(string $status): self
    {
        return new self("A unit in status \"{$status}\" cannot be issued; only a reserved unit can.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::CROSSMATCH_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
