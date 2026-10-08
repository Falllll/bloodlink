<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TtiPanelVerdict;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class UnitReleaseRejected extends DomainException
{
    public static function screeningNotCleared(TtiPanelVerdict $verdict): self
    {
        return new self("A unit can only be released when its full TTI screening panel is non-reactive; the panel verdict is \"{$verdict->value}\".");
    }

    public static function notReleasableFrom(BatchStatus $status): self
    {
        return new self("A blood batch cannot be released from \"{$status->value}\".");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::INVENTORY_RELEASE_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
