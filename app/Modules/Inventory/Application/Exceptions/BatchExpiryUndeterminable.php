<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class BatchExpiryUndeterminable extends DomainException
{
    public static function noProfileFor(string $component, float $temperatureC): self
    {
        return new self(
            "No active WHO storage profile for \"{$component}\" covers {$temperatureC} °C; the expiry cannot be determined."
        );
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BATCH_EXPIRY_UNDETERMINABLE;
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
