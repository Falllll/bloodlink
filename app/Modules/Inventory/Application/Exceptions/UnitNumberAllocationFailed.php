<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class UnitNumberAllocationFailed extends DomainException
{
    public static function sequenceExhausted(string $prefix, string $period): self
    {
        return new self("No unit numbers left for prefix \"{$prefix}\" on {$period}.");
    }

    public static function retriesExhausted(int $attempts): self
    {
        return new self("A unique unit number could not be allocated after {$attempts} attempts.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::UNIT_NUMBER_ALLOCATION_FAILED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
