<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TransferStatus;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class StockTransferRejected extends DomainException
{
    public static function illegal(TransferStatus $from, TransferStatus $to): self
    {
        return new self("Cannot transition a stock transfer from \"{$from->value}\" to \"{$to->value}\".");
    }

    public static function alreadyOpen(): self
    {
        return new self('This unit already has an open stock transfer.');
    }

    public static function unitNotTransferable(BatchStatus $status): self
    {
        return new self("A unit in status \"{$status->value}\" cannot be transferred; only released units can.");
    }

    public static function sameFacility(): self
    {
        return new self('A unit cannot be transferred to the facility that already holds it.');
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
