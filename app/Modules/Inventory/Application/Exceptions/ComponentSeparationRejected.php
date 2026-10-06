<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class ComponentSeparationRejected extends DomainException
{
    public static function notWholeBlood(string $component): self
    {
        return new self("Only whole blood can be separated into components; this unit is \"{$component}\".");
    }

    public static function alreadyDerived(): self
    {
        return new self('A derived component cannot be separated again.');
    }

    public static function notSeparable(BatchStatus $status): self
    {
        return new self("A unit in status \"{$status->value}\" cannot be separated.");
    }

    public static function volumeExceeded(int $requestedMl, int $availableMl): self
    {
        return new self("The components need {$requestedMl} ml but the unit has only {$availableMl} ml.");
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::INVENTORY_SEPARATION_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
