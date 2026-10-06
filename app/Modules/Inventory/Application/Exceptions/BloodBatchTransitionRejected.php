<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class BloodBatchTransitionRejected extends DomainException
{
    public static function illegal(BatchStatus $from, BatchStatus $to): self
    {
        return new self("Cannot transition a blood batch from \"{$from->value}\" to \"{$to->value}\".");
    }

    public static function releaseIsGated(): self
    {
        return new self('A blood batch can only be released through the release gate, not through a status update.');
    }

    public static function separationIsNotAStatusUpdate(): self
    {
        return new self('A blood batch is separated by recording its components, not through a status update.');
    }

    public function errorCode(): ErrorCode
    {
        return ErrorCode::BLOOD_BATCH_TRANSITION_REJECTED;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
