<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Exceptions;

use App\Modules\Inventory\Domain\BatchStatus;
use App\Modules\Inventory\Domain\TtiTestCode;
use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class LabResultRejected extends DomainException
{
    // Do NOT name this $code: RuntimeException already declares an int $code property.
    private function __construct(private readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function notInPanel(TtiTestCode $code): self
    {
        return new self(ErrorCode::LAB_TEST_NOT_IN_PANEL, "\"{$code->value}\" is not part of this facility's screening panel.");
    }

    public static function duplicate(): self
    {
        return new self(ErrorCode::LAB_RESULT_DUPLICATE, 'A result for this unit and test stage has already been recorded.');
    }

    public static function derivedUnit(): self
    {
        return new self(ErrorCode::LAB_RESULT_REJECTED, 'Lab results are recorded on the source unit of the donation, not on a derived component.');
    }

    public static function notUnderTest(BatchStatus $status): self
    {
        return new self(ErrorCode::LAB_RESULT_REJECTED, "Lab results cannot be recorded on a unit in status \"{$status->value}\".");
    }

    public static function confirmatoryWithoutScreen(): self
    {
        return new self(ErrorCode::LAB_RESULT_REJECTED, 'A confirmatory test needs a reactive or indeterminate screening result for the same test first.');
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
