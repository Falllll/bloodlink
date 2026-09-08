<?php

namespace App\Shared\Exceptions;

use App\Shared\Errors\ErrorCode;
use RuntimeException;

abstract class DomainException extends RuntimeException
{
    abstract public function errorCode(): ErrorCode;

    public function httpStatus(): int
    {
        return 422;
    }
}
