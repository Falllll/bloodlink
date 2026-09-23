<?php

declare(strict_types=1);

namespace App\Modules\Donor\Application\Exceptions;

use App\Shared\Errors\ErrorCode;
use App\Shared\Exceptions\DomainException;

final class DeferralConflict extends DomainException
{
    // Do NOT name this $code: RuntimeException already declares an int $code property.
    private function __construct(private readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function inactiveReason(string $code): self
    {
        return new self(ErrorCode::DONOR_DEFERRAL_CONFLICT, "Deferral reason \"{$code}\" is not active.");
    }

    public static function alreadyLifted(): self
    {
        return new self(ErrorCode::DONOR_DEFERRAL_ALREADY_LIFTED, 'This deferral has already been lifted.');
    }

    public static function permanentNotLiftable(): self
    {
        return new self(ErrorCode::DONOR_PERMANENT_DEFERRAL_NOT_LIFTABLE, 'A permanent deferral cannot be lifted.');
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
