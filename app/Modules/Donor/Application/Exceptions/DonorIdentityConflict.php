<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class DonorIdentityConflict extends DomainException
{
    // Do NOT name this $code: RuntimeException already declares an int $code property.
    private function __construct(private readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function duplicateNik(): self
    {
        return new self(ErrorCode::DONOR_DUPLICATE_NIK, 'A donor with this NIK is already registered.');
    }

    public static function duplicatePhone(): self
    {
        return new self(ErrorCode::DONOR_DUPLICATE_PHONE, 'A donor with this phone number is already registered.');
    }

    public static function mergeConflict(string $reason): self
    {
        return new self(ErrorCode::DONOR_MERGE_CONFLICT, $reason);
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
